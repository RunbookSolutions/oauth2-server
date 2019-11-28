<?php
/**
 * OAuth 2.0 Device Code grant.
 *
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) Alex Bilbie
 * @license     http://mit-license.org/
 *
 * @link        https://github.com/thephpleague/oauth2-server
 */

namespace League\OAuth2\Server\Grant;

use DateInterval;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestTypes\DeviceAuthorizationRequest;
use League\OAuth2\Server\ResponseTypes\DeviceCodeResponse;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Device Code grant class.
 */
class DeviceCodeGrant extends AbstractGrant
{
    /**
     * @var DateInterval
     */
    private $deviceCodeTTL;

    /**
     * @var int
     */
    private $retryInterval;

    /**
     * @var string
     */
    private $verificationUri;

    /**
     * @param DeviceCodeRepositoryInterface $deviceCodeRepository
     * @param RefreshTokenRepositoryInterface $refreshTokenRepository
     * @param DateInterval $deviceCodeTTL
     * @param int $retryInterval
     */
    public function __construct(
        DeviceCodeRepositoryInterface $deviceCodeRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        DateInterval $deviceCodeTTL,
        $retryInterval = 5
    ) {
        $this->setDeviceCodeRepository($deviceCodeRepository);
        $this->setRefreshTokenRepository($refreshTokenRepository);

        $this->refreshTokenTTL = new DateInterval('P1M');

        $this->deviceCodeTTL = $deviceCodeTTL;
        $this->retryInterval = $retryInterval;
    }

    /**
     * {@inheritdoc}
     */
    public function canRespondToDeviceAuthorizationRequest(ServerRequestInterface $request)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function validateDeviceAuthorizationRequest(ServerRequestInterface $request)
    {
        $clientId = $this->getRequestParameter(
            'client_id',
            $request,
            $this->getServerParameter('PHP_AUTH_USER', $request)
        );

        if ($clientId === null) {
            throw OAuthServerException::invalidRequest('client_id');
        }

        $client = $this->getClientEntityOrFail($clientId, $request);

        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request, $this->defaultScope));

        $deviceAuthorizationRequest = new DeviceAuthorizationRequest();
        $deviceAuthorizationRequest->setGrantTypeId($this->getIdentifier());
        $deviceAuthorizationRequest->setClient($client);
        $deviceAuthorizationRequest->setScopes($scopes);

        return $deviceAuthorizationRequest;
    }

    /**
     * {@inheritdoc}
     */
    public function completeDeviceAuthorizationRequest(DeviceAuthorizationRequest $deviceRequest)
    {
        $deviceCode = $this->issueDeviceCode(
            $this->deviceCodeTTL,
            $deviceRequest->getClient(),
            $this->verificationUri,
            $deviceRequest->getScopes()
        );

        $payload = [
            'client_id' => $deviceCode->getClient()->getIdentifier(),
            'device_code_id' => $deviceCode->getIdentifier(),
            'scopes' => $deviceCode->getScopes(),
            'user_code' => $deviceCode->getUserCode(),
            'expire_time' => $deviceCode->getExpiryDateTime()->getTimestamp(),
            'verification_uri' => $deviceCode->getVerificationUri()
        ];

        $jsonPayload = \json_encode($payload);

        if ($jsonPayload === false) {
            throw new LogicException('An error was encountered when JSON encoding the authorization request response');
        }

        $response = new DeviceCodeResponse();
        $response->setDeviceCode($deviceCode);
        $response->setPayload($this->encrypt($jsonPayload));

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL
    ) {
        // Validate request
        $client = $this->validateClient($request);
        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request, $this->defaultScope));
        $deviceCode = $this->validateDeviceCode($request, $client);

        // TODO: if the request is too fast, respond with slow down


        // if device code has no user associated, respond with pending
        if (\is_null($deviceCode->getUserIdentifier())) {
            throw OAuthServerException::authorizationPending();
        }

        // Finalize the requested scopes
        $finalizedScopes = $this->scopeRepository->finalizeScopes($scopes, $this->getIdentifier(), $client, $deviceCode->getUserIdentifier());

        // Issue and persist new access token
        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, $deviceCode->getUserIdentifier(), $finalizedScopes);
        $this->getEmitter()->emit(new RequestEvent(RequestEvent::ACCESS_TOKEN_ISSUED, $request));
        $responseType->setAccessToken($accessToken);

        // Issue and persist new refresh token if given
        $refreshToken = $this->issueRefreshToken($accessToken);

        if ($refreshToken !== null) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::REFRESH_TOKEN_ISSUED, $request));
            $responseType->setRefreshToken($refreshToken);
        }

        $this->deviceCodeRepository->revokeDeviceCode($deviceCode->getIdentifier());

        return $responseType;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ClientEntityInterface  $client
     *
     * @throws OAuthServerException
     *
     * @return DeviceCodeEntityInterface
     */
    protected function validateDeviceCode(ServerRequestInterface $request, ClientEntityInterface $client)
    {
        $encryptedDeviceCode = $this->getRequestParameter('device_code', $request);

        if (\is_null($encryptedDeviceCode)) {
            throw OAuthServerException::invalidRequest('device_code');
        }

        try {
            $deviceCodePayload = \json_decode($this->decrypt($encryptedDeviceCode));

            if (!\property_exists($deviceCodePayload, 'device_code_id')) {
                throw OAuthServerException::invalidRequest('device_code', 'Device code malformed');
            }

            if (\time() > $deviceCodePayload->expire_time) {
                throw OAuthServerException::expiredToken('device_code');
            }

            if ($this->deviceCodeRepository->isDeviceCodeRevoked($deviceCodePayload->device_code_id) === true) {
                throw OAuthServerException::invalidRequest('device_code', 'Device code has been revoked');
            }

            if ($deviceCodePayload->client_id !== $client->getIdentifier()) {
                throw OAuthServerException::invalidRequest('device_code', 'Device code was not issued to this client');
            }

        } catch (\LogicException $e) {
            throw OAuthServerException::invalidRequest('device_code', 'Cannot decrypt the device code', $e);
        }

        $deviceCode = $this->deviceCodeRepository->getDeviceCodeEntityByDeviceCode(
            $deviceCodePayload->device_code_id,
            $this->getIdentifier(),
            $client
        );

        if ($deviceCode instanceof DeviceCodeEntityInterface === false) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::USER_AUTHENTICATION_FAILED, $request));

            throw OAuthServerException::invalidGrant();
        }

        return $deviceCode;
    }

    /**
     * Set the verification uri
     *
     * @param $verificationUri
     */
    public function setVerificationUri($verificationUri)
    {
        $this->verificationUri = $verificationUri;
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier()
    {
        return 'device_code';
    }
}