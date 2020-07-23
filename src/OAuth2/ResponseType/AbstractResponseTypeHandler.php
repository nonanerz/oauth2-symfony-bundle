<?php

/**
 * This file is part of the authbucket/oauth2-php package.
 *
 * (c) Wong Hoi Sing Edison <hswong3i@pantarei-design.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AuthBucket\OAuth2\ResponseType;

use AuthBucket\OAuth2\Exception\InvalidGrantException;
use AuthBucket\OAuth2\Exception\InvalidRequestException;
use AuthBucket\OAuth2\Exception\InvalidScopeException;
use AuthBucket\OAuth2\Exception\ServerErrorException;
use AuthBucket\OAuth2\Exception\UnauthorizedClientException;
use AuthBucket\OAuth2\Model\AuthorizeInterface;
use AuthBucket\OAuth2\Model\ModelManagerFactoryInterface;
use AuthBucket\OAuth2\TokenType\TokenTypeHandlerFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Shared response type implementation.
 *
 * @author Wong Hoi Sing Edison <hswong3i@pantarei-design.com>
 */
abstract class AbstractResponseTypeHandler implements ResponseTypeHandlerInterface
{
    const GRANT_TYPE = null;
    protected $tokenStorage;
    protected $validator;
    protected $modelManagerFactory;
    protected $tokenTypeHandlerFactory;

    public function __construct(
        TokenStorageInterface $tokenStorage,
        ValidatorInterface $validator,
        ModelManagerFactoryInterface $modelManagerFactory,
        TokenTypeHandlerFactoryInterface $tokenTypeHandlerFactory
    ) {
        $this->tokenStorage = $tokenStorage;
        $this->validator = $validator;
        $this->modelManagerFactory = $modelManagerFactory;
        $this->tokenTypeHandlerFactory = $tokenTypeHandlerFactory;
    }

    /**
     * Fetch username from authenticated token.
     *
     * @return string Supplied username from authenticated token
     *
     * @throw ServerErrorException If supplied token is not a standard TokenInterface instance.
     */
    protected function checkUsername()
    {
        $token = $this->tokenStorage->getToken();
        if (!$token->getUsername()) {
            throw new ServerErrorException(['error_description' => 'The authorization server encountered an unexpected condition that prevented it from fulfilling the request.']);
        }

        return $token->getUsername();
    }

    /**
     * Fetch cliend_id from GET.
     *
     * @param Request $request Incoming request object
     *
     * @return string Supplied client_id from incoming request
     *
     * @throw InvalidRequestException If supplied client_id in bad format.
     * @throw UnauthorizedClientException If client_id not found from database record.
     */
    protected function checkClientId(Request $request)
    {
        // client_id is required and in valid format.
        $clientId = $request->query->get('client_id');
        $errors = $this->validator->validate($clientId, [
            new \Symfony\Component\Validator\Constraints\NotBlank(),
            new \AuthBucket\OAuth2\Symfony\Component\Validator\Constraints\ClientId(),
        ]);
        if (count($errors) > 0) {
            throw new InvalidRequestException(['error_description' => 'The request includes an invalid parameter value.']);
        }

        // Compare client_id with database record.
        $clientManager = $this->modelManagerFactory->getModelManager('client');
        $result = $clientManager->readModelOneBy([
            'clientId' => $clientId,
        ]);
        if ($result === null) {
            throw new UnauthorizedClientException(['error_description' => 'The client is not authorized.']);
        }

        return $clientId;
    }

    /**
     * Fetch redirect_uri from GET.
     *
     * @param Request $request  Incoming request object
     * @param string  $clientId Corresponding client_id that code should belongs to
     *
     * @return string The supplied redirect_uri from incoming request, or from stored record
     *
     * @throw InvalidRequestException If redirect_uri not exists in both incoming request and database record, or supplied value not match with stord record.
     */
    protected function checkRedirectUri(
        Request $request,
        $clientId
    ) {
        // redirect_uri may not exists.
        $redirectUri = $request->query->get('redirect_uri');
        $errors = $this->validator->validate($redirectUri, [
            new \AuthBucket\OAuth2\Symfony\Component\Validator\Constraints\RedirectUri(),
        ]);
        if (count($errors) > 0) {
            throw new InvalidRequestException(['error_description' => 'The request includes an invalid parameter value.']);
        }

        // redirect_uri is not required if already established via other channels,
        // check an existing redirect URI against the one supplied.
        $redirectUriStored = null;
        $clientManager = $this->modelManagerFactory->getModelManager('client');
        $result = $clientManager->readModelOneBy([
            'clientId' => $clientId,
        ]);
        if ($result !== null && $result->getRedirectUri()) {
            $redirectUriStored = $result->getRedirectUri();
        }

        // At least one of: existing redirect URI or input redirect URI must be
        // specified.
        if (!$redirectUriStored && !$redirectUri) {
            throw new InvalidRequestException(['error_description' => 'The request is missing a required parameter.']);
        }

        // If there's an existing uri and one from input, verify that they match.
        if ($redirectUriStored && $redirectUri) {
            // Ensure that the input uri starts with the stored uri.
            if (strcasecmp(substr($redirectUri, 0, strlen($redirectUriStored)), $redirectUriStored) !== 0) {
                throw new InvalidRequestException(['error_description' => 'The request includes an invalid parameter value']);
            }
        }

        return $redirectUri
            ?: $redirectUriStored;
    }

    protected function checkState(
        Request $request,
        $redirectUri
    ) {
        // state is required and in valid format.
        $state = $request->query->get('state');
        $errors = $this->validator->validate($state, [
            new \Symfony\Component\Validator\Constraints\NotBlank(),
            new \AuthBucket\OAuth2\Symfony\Component\Validator\Constraints\State(),
        ]);
        if (count($errors) > 0) {
            throw new InvalidRequestException(['redirect_uri' => $redirectUri, 'error_description' => 'The request includes an invalid parameter value']);
        }

        return $state;
    }

    protected function checkScope(
        Request $request,
        $clientId,
        $username,
        $redirectUri,
        $state
    ) {
        // scope may not exists.
        $scope = $request->query->get('scope');
        if (empty($scope)) {
            return;
        }

        // scope must be in valid format.
        $errors = $this->validator->validate($scope, [
            new \Symfony\Component\Validator\Constraints\NotBlank(),
            new \AuthBucket\OAuth2\Symfony\Component\Validator\Constraints\Scope(),
        ]);
        if (count($errors) > 0) {
            throw new InvalidRequestException(['redirect_uri' => $redirectUri, 'state' => $state, 'error_description' => 'The request includes an invalid parameter value.']);
        }

        $scope = preg_split('/\s+/', $scope);

        // Compare if given scope within all supported scopes.
        $scopeSupported = [];
        $scopeManager = $this->modelManagerFactory->getModelManager('scope');
        $result = $scopeManager->readModelAll();
        if ($result !== null) {
            foreach ($result as $row) {
                $scopeSupported[] = $row->getScope();
            }
        }
        if (array_intersect($scope, $scopeSupported) !== $scope) {
            throw new InvalidScopeException(['redirect_uri' => $redirectUri, 'state' => $state, 'error_description' => 'The requested scope is unknown.']);
        }

        // Compare if given scope within all authorized scopes.
        $scopeAuthorized = [];
        $authorizeManager = $this->modelManagerFactory->getModelManager('authorize');
        /** @var AuthorizeInterface $result */
        $result = $authorizeManager->readModelOneBy([
            'clientId' => $clientId,
            'username' => $username,
        ]);
        if ($result !== null) {
            $scopeAuthorized = $result->getScope();
        }
        if (array_intersect($scope, $scopeAuthorized) !== $scope) {
            throw new InvalidScopeException(['redirect_uri' => $redirectUri, 'state' => $state, 'error_description' => 'The requested scope is invalid.']);
        }

        if (!in_array(static::GRANT_TYPE, $result->getGrantType())) {
            throw new InvalidGrantException(['state' => $state, 'error_description' => 'The requested grant is invalid.']);
        }

        return $scope;
    }
}
