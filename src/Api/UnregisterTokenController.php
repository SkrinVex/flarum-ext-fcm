<?php

namespace Komari\Fcm\Api;

use Flarum\Http\RequestUtil;
use Komari\Fcm\Model\FcmToken;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class UnregisterTokenController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $token = trim($request->getParsedBody()['token'] ?? '');

        FcmToken::where('user_id', $actor->id)->where('token', $token)->delete();

        return new EmptyResponse(204);
    }
}
