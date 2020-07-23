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

use AuthBucket\OAuth2\Exception\UnsupportedGrantTypeException;
use AuthBucket\OAuth2\Model\ModelManagerFactoryInterface;
use AuthBucket\OAuth2\TokenType\TokenTypeHandlerFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Encoder\EncoderFactoryInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * OAuth2 grant type handler factory implemention.
 *
 * @author Wong Hoi Sing Edison <hswong3i@pantarei-design.com>
 */
class GrantTypeHandlerFactory implements GrantTypeHandlerFactoryInterface
{
    protected $tokenStorage;
    protected $encoderFactory;
    protected $validator;
    protected $modelManagerFactory;
    protected $tokenTypeHandlerFactory;
    protected $userProvider;
    protected $classes;

    public function __construct(
        TokenStorageInterface $tokenStorage,
        EncoderFactoryInterface $encoderFactory,
        ValidatorInterface $validator,
        ModelManagerFactoryInterface $modelManagerFactory,
        TokenTypeHandlerFactoryInterface $tokenTypeHandlerFactory,
        UserProviderInterface $userProvider = null,
        array $classes = []
    ) {
        $this->tokenStorage = $tokenStorage;
        $this->encoderFactory = $encoderFactory;
        $this->validator = $validator;
        $this->modelManagerFactory = $modelManagerFactory;
        $this->tokenTypeHandlerFactory = $tokenTypeHandlerFactory;
        $this->userProvider = $userProvider;

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                throw new UnsupportedGrantTypeException(['error_description' => 'The authorization grant type is not supported by the authorization server.']);
            }

            $reflection = new \ReflectionClass($class);
            if (!$reflection->implementsInterface('AuthBucket\\OAuth2\\GrantType\\GrantTypeHandlerInterface')) {
                throw new UnsupportedGrantTypeException(['error_description' => 'The authorization grant type is not supported by the authorization server.']);
            }
        }

        $this->classes = $classes;
    }

    public function getGrantTypeHandler($type = null)
    {
        $type = $type ?: current(array_keys($this->classes));

        if (!isset($this->classes[$type]) || !class_exists($this->classes[$type])) {
            throw new UnsupportedGrantTypeException(['error_description' => 'The authorization grant type is not supported by the authorization server.']);
        }

        $class = $this->classes[$type];

        return new $class(
            $this->tokenStorage,
            $this->encoderFactory,
            $this->validator,
            $this->modelManagerFactory,
            $this->tokenTypeHandlerFactory,
            $this->userProvider
        );
    }

    public function getGrantTypeHandlers()
    {
        return $this->classes;
    }
}
