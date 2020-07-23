<?php

/**
 * This file is part of the authbucket/oauth2-php package.
 *
 * (c) Wong Hoi Sing Edison <hswong3i@pantarei-design.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AuthBucket\OAuth2\GrantType;

use AuthBucket\OAuth2\Exception\InvalidGrantException;
use AuthBucket\OAuth2\Exception\InvalidRequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authorization code grant type implementation.
 *
 * @author Wong Hoi Sing Edison <hswong3i@pantarei-design.com>
 */
class AuthorizationCodeGrantTypeHandler extends AbstractGrantTypeHandler
{
    const GRANT_TYPE = 'authorization_code';

    public function handle(Request $request)
    {
        // Fetch client_id from authenticated token.
        $clientId = $this->checkClientId();

        // Check and set redirect_uri.
        $redirectUri = $this->checkRedirectUri($request, $clientId);

        // Fetch username and scope from stored code.
        list($username, $scope) = $this->checkCode($request, $clientId);

        // Generate access_token, store to backend and set token response.
        $parameters = $this->tokenTypeHandlerFactory
            ->getTokenTypeHandler()
            ->createAccessToken(
                $clientId,
                $username,
                $scope
            );

        return new JsonResponse($parameters, 200, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Fetch code from POST.
     *
     * @param Request $request  Incoming request object
     * @param string  $clientId Corresponding client_id that code should belongs to
     *
     * @return array A list with stored username and scope, originally grant in authorize endpoint
     *
     * @throw InvalidRequestException If code in invalid format.
     * @throw InvalidGrantException If code provided is no longer valid.
     */
    private function checkCode(
        Request $request,
        $clientId
    ) {
        // code is required and must in valid format.
        $code = $request->request->get('code');
        $errors = $this->validator->validate($code, [
            new \Symfony\Component\Validator\Constraints\NotBlank(),
            new \AuthBucket\OAuth2\Symfony\Component\Validator\Constraints\Code(),
        ]);
        if (count($errors) > 0) {
            throw new InvalidRequestException(['error_description' => 'The request includes an invalid parameter value.']);
        }

        // Check code with database record.
        $codeManager = $this->modelManagerFactory->getModelManager('code');
        $result = $codeManager->readModelOneBy([
            'code' => $code,
        ]);
        if ($result === null || $result->getClientId() !== $clientId) {
            throw new InvalidGrantException(['error_description' => 'The provided authorization grant is invalid.']);
        } elseif ($result->getExpires() < new \DateTime()) {
            throw new InvalidGrantException(['error_description' => 'The provided authorization grant is expired.']);
        }

        // Delete this code so it can only be used once
        $codeManager->deleteModel($result);

        return [$result->getUsername(), $result->getScope()];
    }

    /**
     * Fetch redirect_uri from POST, or stored record.
     *
     * @param Request $request  Incoming request object
     * @param string  $clientId Corresponding client_id that code should belongs to
     *
     * @return string The supplied redirect_uri from incoming request, or from stored record
     *
     * @throw InvalidRequestException If redirect_uri not exists in both incoming request and database record, or supplied value not match with stord record.
     */
    private function checkRedirectUri(
        Request $request,
        $clientId
    ) {
        // redirect_uri may not exists.
        $redirectUri = $request->request->get('redirect_uri');
        $errors = $this->validator->validate($redirectUri, [
            new \AuthBucket\OAuth2\Symfony\Component\Validator\Constraints\RedirectUri(),
        ]);
        if (count($errors) > 0) {
            throw new InvalidRequestException(['error_description' => 'The request includes an invalid parameter value.']);
        }

        // redirect_uri is not required if already established via other channels,
        // check an existing redirect URI against the one supplied.
        $stored = null;
        $clientManager = $this->modelManagerFactory->getModelManager('client');
        $result = $clientManager->readModelOneBy([
            'clientId' => $clientId,
        ]);
        if ($result !== null && $result->getRedirectUri()) {
            $stored = $result->getRedirectUri();
        }

        // At least one of: existing redirect URI or input redirect URI must be
        // specified.
        if (!$stored && !$redirectUri) {
            throw new InvalidRequestException(['error_description' => 'The request is missing a required parameter.']);
        }

        // If there's an existing uri and one from input, verify that they match.
        if ($stored && $redirectUri) {
            // Ensure that the input uri starts with the stored uri.
            if (strcasecmp(substr($redirectUri, 0, strlen($stored)), $stored) !== 0) {
                throw new InvalidGrantException(['error_description' => 'The provided authorization grant does not match the redirection URI used in the authorization request.']);
            }
        }

        return $redirectUri ?: $stored;
    }
}
