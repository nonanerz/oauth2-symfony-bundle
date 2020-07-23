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
use AuthBucket\OAuth2\Exception\InvalidScopeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Refresh token grant type implementation.
 *
 * @author Wong Hoi Sing Edison <hswong3i@pantarei-design.com>
 */
class RefreshTokenGrantTypeHandler extends AbstractGrantTypeHandler
{
    const GRANT_TYPE = 'refresh_token';

    public function handle(Request $request)
    {
        // Fetch client_id from authenticated token.
        $clientId = $this->checkClientId();

        // Check refresh_token, then fetch username and scope.
        list($username, $scope) = $this->checkRefreshToken($request, $clientId);

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
     * Check refresh_token supplied, return stored username and scope.
     *
     * @param Request $request  Incoming request object
     * @param string  $clientId Corresponding client_id that refresh_token should belongs to
     *
     * @return array A list with stored username and scope, originally grant in authorize endpoint
     *
     * @throw InvalidRequestException If supplied refresh_token or scope in invalid format.
     * @throw InvalidGrantException If refresh_token not belongs to give client_id, or already expired.
     * @throw InvalidScopeException If supplied scope outside supported scope range.
     */
    private function checkRefreshToken(
        Request $request,
        $clientId
    ) {
        // refresh_token must exists and in valid format.
        $refreshToken = $request->request->get('refresh_token');
        $errors = $this->validator->validate($refreshToken, [
            new \Symfony\Component\Validator\Constraints\NotBlank(),
            new \AuthBucket\OAuth2\Symfony\Component\Validator\Constraints\RefreshToken(),
        ]);
        if (count($errors) > 0) {
            throw new InvalidRequestException(['error_description' => 'The request includes an invalid parameter value.']);
        }

        // scope may not exists, else must be in valid format.
        $scope = $request->request->get('scope');
        $errors = $this->validator->validate($scope, [
            new \AuthBucket\OAuth2\Symfony\Component\Validator\Constraints\Scope(),
        ]);
        if (count($errors) > 0) {
            throw new InvalidRequestException(['error_description' => 'The request includes an invalid parameter value.']);
        }

        // Check refresh_token with database record.
        $refreshTokenManager = $this->modelManagerFactory->getModelManager('refresh_token');
        $result = $refreshTokenManager->readModelOneBy([
            'refreshToken' => $refreshToken,
        ]);
        if ($result === null || $result->getClientId() !== $clientId) {
            throw new InvalidGrantException(['error_description' => 'The provided refresh token was issued to another client.']);
        } elseif ($result->getExpires() < new \DateTime()) {
            throw new InvalidGrantException(['error_description' => 'The provided refresh token is expired.']);
        }

        // Fetch username from stored refresh_token.
        $username = $result->getUsername();

        // Fetch scope from pre-grnerated refresh_token.
        $scopeGranted = null;
        if ($result !== null && $result->getClientId() == $clientId && $result->getScope()) {
            $scopeGranted = $result->getScope();
        }

        // Compare if given scope is subset of original refresh_token's scope.
        if ($scope !== null && $scopeGranted !== null) {
            // Compare if given scope within all available granted scopes.
            $scope = preg_split('/\s+/', $scope);
            if (array_intersect($scope, $scopeGranted) !== $scope) {
                throw new InvalidScopeException(['error_description' => 'The requested scope exceeds the scope granted by the resource owner.']);
            }
        }
        // Return original refresh_token's scope if not specify in new request.
        elseif ($scopeGranted !== null) {
            $scope = $scopeGranted;
        }

        if ($scope !== null) {
            // Compare if given scope within all supported scopes.
            $scopeSupported = [];
            $scopeManager = $this->modelManagerFactory->getModelManager('scope');
            $scopeResult = $scopeManager->readModelAll();
            if ($scopeResult !== null) {
                foreach ($scopeResult as $row) {
                    $scopeSupported[] = $row->getScope();
                }
            }
            if (array_intersect($scope, $scopeSupported) !== $scope) {
                throw new InvalidScopeException(['error_description' => 'The requested scope is unknown.']);
            }

            // Compare if given scope within all authorized scopes.
            $scopeAuthorized = [];
            $authorizeManager = $this->modelManagerFactory->getModelManager('authorize');
            $authorizeResult = $authorizeManager->readModelOneBy([
                'clientId' => $clientId,
                'username' => $username,
            ]);
            if ($authorizeResult !== null) {
                $scopeAuthorized = $authorizeResult->getScope();
            }
            if (array_intersect($scope, $scopeAuthorized) !== $scope) {
                throw new InvalidScopeException(['error_description' => 'The requested scope exceeds the scope granted by the resource owner.']);
            }
        }

        // Expire this refresh token and new one will be issued
        $result->setExpires(new \DateTime('+ 5 minutes'));

        return [$username, $scope];
    }
}
