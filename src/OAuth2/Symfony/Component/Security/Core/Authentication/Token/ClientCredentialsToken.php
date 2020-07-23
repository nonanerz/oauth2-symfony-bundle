<?php

/**
 * This file is part of the authbucket/oauth2-php package.
 *
 * (c) Wong Hoi Sing Edison <hswong3i@pantarei-design.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AuthBucket\OAuth2\Symfony\Component\Security\Core\Authentication\Token;

use AuthBucket\OAuth2\Model\ClientInterface;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;

/**
 * OAuth2 ClientCredentialsToken for token endpoint authentication.
 */
class ClientCredentialsToken extends AbstractToken implements ClientInterface
{
    protected $providerKey;

    protected $clientId;
    protected $clientSecret;
    protected $redirectUri;

    public function __construct(
        $providerKey,
        $clientId,
        $clientSecret,
        $redirectUri = '',
        array $roles = []
    ) {
        parent::__construct($roles);

        $this->providerKey = $providerKey;

        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;

        parent::setAuthenticated(count($roles) > 0);
    }

    public function getId()
    {
        return 0;
    }

    public function setClientId($clientId)
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getClientId()
    {
        return $this->clientId;
    }

    public function setClientSecret($clientSecret)
    {
        $this->clientSecret = $clientSecret;

        return $this;
    }

    public function getClientSecret()
    {
        return $this->clientSecret;
    }

    public function setRedirectUri($redirectUri)
    {
        $this->redirectUri = $redirectUri;

        return $this;
    }

    public function getRedirectUri()
    {
        return $this->redirectUri;
    }

    public function getProviderKey()
    {
        return $this->providerKey;
    }

    public function getCredentials()
    {
        return '';
    }

    public function __serialize(): array
    {
        return [
            $this->providerKey,
            $this->clientId,
            $this->clientSecret,
            $this->redirectUri,
            parent::__serialize(),
        ];
    }

    public function __unserialize($str): void
    {
        list(
            $this->providerKey,
            $this->clientId,
            $this->clientSecret,
            $this->redirectUri,
            $parentStr
        ) = unserialize($str);
    }
}
