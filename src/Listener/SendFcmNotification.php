<?php

namespace Komari\Fcm\Listener;

use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Komari\Fcm\Model\FcmToken;
use Psr\Log\LoggerInterface;

class SendFcmNotification
{
    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Client $client,
        private LoggerInterface $logger
    ) {}

    public function __invoke(BlueprintInterface $blueprint, array $recipients): array
    {
        $serviceAccountPath = $this->settings->get('komari-fcm.service_account_path')
            ?: '/var/www/forum.skrinvex.su/storage/fcm-service-account.json';

        if (!file_exists($serviceAccountPath)) {
            $this->logger->error('[FCM] Service account file not found: ' . $serviceAccountPath);
            return $recipients;
        }

        foreach ($recipients as $user) {
            $tokens = FcmToken::where('user_id', $user->id)->pluck('token')->toArray();
            if (empty($tokens)) continue;

            $title = $this->getTitle($blueprint);
            $body  = $this->getBody($blueprint);
            $data  = $this->getData($blueprint);

            try {
                $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
                $accessToken = $this->getAccessToken($serviceAccount);
                $projectId = $serviceAccount['project_id'];

                foreach ($tokens as $token) {
                    $this->sendToToken($accessToken, $projectId, $token, $title, $body, $data);
                }
            } catch (\Throwable $e) {
                $this->logger->error('[FCM] Error: ' . $e->getMessage());
            }
        }

        return $recipients;
    }

    // Generate a short-lived OAuth2 access token from the service account using a signed JWT
    private function getAccessToken(array $serviceAccount): string
    {
        $now = time();
        $header  = $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64url(json_encode([
            'iss'   => $serviceAccount['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signingInput = $header . '.' . $payload;
        openssl_sign($signingInput, $signature, $serviceAccount['private_key'], 'SHA256');
        $jwt = $signingInput . '.' . $this->base64url($signature);

        $response = $this->client->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
        ]);

        return json_decode((string) $response->getBody(), true)['access_token'];
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function sendToToken(string $accessToken, string $projectId, string $token, string $title, string $body, array $data): void
    {
        $this->client->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'message' => [
                    'token'        => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data'         => array_map('strval', $data),
                    'android'      => ['notification' => ['sound' => 'default']],
                ],
            ],
        ]);
    }

    private function getTitle(BlueprintInterface $blueprint): string
    {
        $map = [
            'postLiked'                       => 'New like',
            'newPost'                         => 'New reply',
            'userMentioned'                   => 'You were mentioned',
            'postMentioned'                   => 'Reply to your post',
            'newDiscussion'                   => 'New discussion',
            'byobuPrivateDiscussionCreated'   => 'New private message',
            'byobuPrivateDiscussionReplied'   => 'Reply in private message',
            'byobuRecipientRemoved'           => 'Removed from conversation',
            'byobuPrivateDiscussionAdded'     => 'Added to conversation',
        ];
        return $map[$blueprint::getType()] ?? 'New notification';
    }

    private function getBody(BlueprintInterface $blueprint): string
    {
        $subject  = $blueprint->getSubject();
        $username = $blueprint->getFromUser()?->display_name ?? 'Someone';

        if (method_exists($subject, 'discussion')) {
            return $username . ' in «' . ($subject->discussion?->title ?? '') . '»';
        }
        if (method_exists($subject, 'title')) {
            return $username . ': ' . $subject->title;
        }
        return $username;
    }

    private function getData(BlueprintInterface $blueprint): array
    {
        $subject = $blueprint->getSubject();
        $data = ['type' => $blueprint::getType()];

        // Subject is a Post (replies, mentions, likes, byobu)
        if (method_exists($subject, 'discussion') && $subject->discussion) {
            $data['discussion_id'] = (string) $subject->discussion->id;
            $data['post_id']       = (string) $subject->id;
        }
        // Subject is a Discussion (newDiscussion)
        elseif (isset($subject->id) && $blueprint::getType() === 'newDiscussion') {
            $data['discussion_id'] = (string) $subject->id;
        }

        return $data;
    }
}
