<?php

namespace LeagueTests\ResponseTypes;

use League\OAuth2\Server\AuthorizationValidators\BearerTokenValidator;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use LeagueTests\Stubs\AccessTokenEntity;
use LeagueTests\Stubs\ClientEntity;
use LeagueTests\Stubs\RefreshTokenEntity;
use LeagueTests\Stubs\ScopeEntity;
use Psr\Http\Message\ResponseInterface;
use Zend\Diactoros\Response;
use Zend\Diactoros\ServerRequest;

class BearerResponseTypeTest extends \PHPUnit_Framework_TestCase
{
    public function testGenerateHttpResponse()
    {
        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();

        $responseType = new BearerTokenResponse($accessTokenRepositoryMock);
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $client = new ClientEntity();
        $client->setIdentifier('clientName');

        $scope = new ScopeEntity();
        $scope->setIdentifier('basic');

        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier('abcdef');
        $accessToken->setExpiryDateTime((new \DateTime())->add(new \DateInterval('PT1H')));
        $accessToken->setClient($client);
        $accessToken->addScope($scope);

        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier('abcdef');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime((new \DateTime())->add(new \DateInterval('PT1H')));

        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        $response = $responseType->generateHttpResponse(new Response());

        $this->assertTrue($response instanceof ResponseInterface);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('no-cache', $response->getHeader('pragma')[0]);
        $this->assertEquals('no-store', $response->getHeader('cache-control')[0]);
        $this->assertEquals('application/json; charset=UTF-8', $response->getHeader('content-type')[0]);

        $response->getBody()->rewind();
        $json = json_decode($response->getBody()->getContents());
        $this->assertEquals('Bearer', $json->token_type);
        $this->assertTrue(isset($json->expires_in));
        $this->assertTrue(isset($json->access_token));
        $this->assertTrue(isset($json->refresh_token));
    }

    public function testGenerateHttpResponseWithExtraParams()
    {
        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();

        $responseType = new BearerTokenResponseWithParams($accessTokenRepositoryMock);
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $client = new ClientEntity();
        $client->setIdentifier('clientName');

        $scope = new ScopeEntity();
        $scope->setIdentifier('basic');

        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier('abcdef');
        $accessToken->setExpiryDateTime((new \DateTime())->add(new \DateInterval('PT1H')));
        $accessToken->setClient($client);
        $accessToken->addScope($scope);

        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier('abcdef');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime((new \DateTime())->add(new \DateInterval('PT1H')));

        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        $response = $responseType->generateHttpResponse(new Response());

        $this->assertTrue($response instanceof ResponseInterface);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('no-cache', $response->getHeader('pragma')[0]);
        $this->assertEquals('no-store', $response->getHeader('cache-control')[0]);
        $this->assertEquals('application/json; charset=UTF-8', $response->getHeader('content-type')[0]);

        $response->getBody()->rewind();
        $json = json_decode($response->getBody()->getContents());
        $this->assertEquals('Bearer', $json->token_type);
        $this->assertTrue(isset($json->expires_in));
        $this->assertTrue(isset($json->access_token));
        $this->assertTrue(isset($json->refresh_token));

        $this->assertTrue(isset($json->foo));
        $this->assertEquals('bar', $json->foo);
    }

    public function testDetermineAccessTokenInHeaderValidToken()
    {
        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();
        $accessTokenRepositoryMock->method('isAccessTokenRevoked')->willReturn(false);

        $responseType = new BearerTokenResponse($accessTokenRepositoryMock);
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $client = new ClientEntity();
        $client->setIdentifier('clientName');

        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier('abcdef');
        $accessToken->setUserIdentifier(123);
        $accessToken->setExpiryDateTime((new \DateTime())->add(new \DateInterval('PT1H')));
        $accessToken->setClient($client);

        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier('abcdef');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime((new \DateTime())->add(new \DateInterval('PT1H')));

        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        $response = $responseType->generateHttpResponse(new Response());
        $json = json_decode((string) $response->getBody());

        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();
        $accessTokenRepositoryMock->method('isAccessTokenRevoked')->willReturn(false);

        $authorizationValidator = new BearerTokenValidator($accessTokenRepositoryMock);
        $authorizationValidator->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $authorizationValidator->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $request = new ServerRequest();
        $request = $request->withHeader('authorization', sprintf('Bearer %s', $json->access_token));

        $request = $authorizationValidator->validateAuthorization($request);

        $this->assertEquals('abcdef', $request->getAttribute('oauth_access_token_id'));
        $this->assertEquals('clientName', $request->getAttribute('oauth_client_id'));
        $this->assertEquals('123', $request->getAttribute('oauth_user_id'));
        $this->assertEquals([], $request->getAttribute('oauth_scopes'));
    }

    public function testDetermineAccessTokenInHeaderInvalidJWT()
    {
        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();
        $accessTokenRepositoryMock->method('isAccessTokenRevoked')->willReturn(false);

        $responseType = new BearerTokenResponse($accessTokenRepositoryMock);
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $client = new ClientEntity();
        $client->setIdentifier('clientName');

        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier('abcdef');
        $accessToken->setUserIdentifier(123);
        $accessToken->setExpiryDateTime((new \DateTime())->add(new \DateInterval('PT1H')));
        $accessToken->setClient($client);

        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier('abcdef');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime((new \DateTime())->add(new \DateInterval('PT1H')));

        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        $response = $responseType->generateHttpResponse(new Response());
        $json = json_decode((string) $response->getBody());

        $authorizationValidator = new BearerTokenValidator($accessTokenRepositoryMock);
        $authorizationValidator->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $authorizationValidator->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $request = new ServerRequest();
        $request = $request->withHeader('authorization', sprintf('Bearer %s', $json->access_token . 'foo'));

        try {
            $authorizationValidator->validateAuthorization($request);
        } catch (OAuthServerException $e) {
            $this->assertEquals(
                'Access token could not be verified',
                $e->getHint()
            );
        }
    }

    public function testDetermineAccessTokenInHeaderRevokedToken()
    {
        $responseType = new BearerTokenResponse();
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $client = new ClientEntity();
        $client->setIdentifier('clientName');

        $accessToken = new AccessTokenEntity();
        $accessToken->setIdentifier('abcdef');
        $accessToken->setUserIdentifier(123);
        $accessToken->setExpiryDateTime((new \DateTime())->add(new \DateInterval('PT1H')));
        $accessToken->setClient($client);

        $refreshToken = new RefreshTokenEntity();
        $refreshToken->setIdentifier('abcdef');
        $refreshToken->setAccessToken($accessToken);
        $refreshToken->setExpiryDateTime((new \DateTime())->add(new \DateInterval('PT1H')));

        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        $response = $responseType->generateHttpResponse(new Response());
        $json = json_decode((string) $response->getBody());

        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();
        $accessTokenRepositoryMock->method('isAccessTokenRevoked')->willReturn(true);

        $authorizationValidator = new BearerTokenValidator($accessTokenRepositoryMock);
        $authorizationValidator->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $authorizationValidator->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $request = new ServerRequest();
        $request = $request->withHeader('authorization', sprintf('Bearer %s', $json->access_token));

        try {
            $authorizationValidator->validateAuthorization($request);
        } catch (OAuthServerException $e) {
            $this->assertEquals(
                'Access token has been revoked',
                $e->getHint()
            );
        }
    }

    public function testDetermineAccessTokenInHeaderInvalidToken()
    {
        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();

        $responseType = new BearerTokenResponse($accessTokenRepositoryMock);
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();

        $authorizationValidator = new BearerTokenValidator($accessTokenRepositoryMock);
        $authorizationValidator->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $authorizationValidator->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $request = new ServerRequest();
        $request = $request->withHeader('authorization', 'Bearer blah');

        try {
            $authorizationValidator->validateAuthorization($request);
        } catch (OAuthServerException $e) {
            $this->assertEquals(
                'The JWT string must have two dots',
                $e->getHint()
            );
        }
    }

    public function testDetermineMissingBearerInHeader()
    {
        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();

        $responseType = new BearerTokenResponse($accessTokenRepositoryMock);
        $responseType->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $responseType->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $accessTokenRepositoryMock = $this->getMockBuilder(AccessTokenRepositoryInterface::class)->getMock();

        $authorizationValidator = new BearerTokenValidator($accessTokenRepositoryMock);
        $authorizationValidator->setPrivateKey(new CryptKey('file://' . __DIR__ . '/../Stubs/private.key'));
        $authorizationValidator->setPublicKey(new CryptKey('file://' . __DIR__ . '/../Stubs/public.key'));

        $request = new ServerRequest();
        $request = $request->withHeader('authorization', 'Bearer blah.blah.blah');

        try {
            $authorizationValidator->validateAuthorization($request);
        } catch (OAuthServerException $e) {
            $this->assertEquals(
                'Error while decoding to JSON',
                $e->getHint()
            );
        }
    }
}
