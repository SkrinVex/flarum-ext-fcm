<?php

namespace Komari\Fcm\Api;

use Flarum\Http\RequestUtil;
use Komari\Fcm\Model\FcmToken;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RegisterTokenController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body = $request->getParsedBody();
        $token = trim($body['token'] ?? '');
        $deviceName = substr(trim($body['device_name'] ?? 'Android'), 0, 100);

        if (!$token) {
            return new JsonResponse(['error' => 'FCM token is required'], 400);
        }

        FcmToken::updateOrCreate(
            ['token' => $token],
            ['user_id' => $actor->id, 'device_name' => $deviceName]
        );

        return new JsonResponse(['status' => 'ok']);
    }
}
