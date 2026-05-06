# FCM Push Notifications for Flarum

A Flarum extension that sends Firebase Cloud Messaging (FCM) push notifications to native Android/iOS apps. Uses the **FCM HTTP v1 API** (service account authentication) — the legacy server key API is not supported.

[![Flarum](https://img.shields.io/badge/Flarum-%5E1.8-purple)](https://flarum.org)
[![License](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

---

## Requirements

- Flarum **^1.8**
- PHP **^8.0**
- A Firebase project with a **service account JSON** key
- A native Android/iOS app that integrates the Firebase SDK

---

## Installation

### 1. Download the extension

Clone or download this repository and place it in your Flarum's `packages/` directory:

```bash
mkdir -p /var/www/your-forum/packages
cd /var/www/your-forum/packages
git clone https://github.com/SkrinVex/flarum-ext-fcm.git
```

### 2. Register the local path repository

```bash
cd /var/www/your-forum
composer config repositories.fcm path packages/flarum-ext-fcm
```

### 3. Require the package

```bash
composer require "komari/flarum-ext-fcm:*@dev"
```

### 4. Build the admin JS

```bash
cd packages/flarum-ext-fcm/js
npm install
npm run build
```

### 5. Run migrations and clear cache

```bash
cd /var/www/your-forum
php flarum migrate
php flarum cache:clear
```

### 6. Enable the extension

Go to **Admin Panel → Extensions → FCM Push Notifications** and enable it.

---

## Firebase Setup

1. Open [Firebase Console](https://console.firebase.google.com) and create (or open) your project
2. Go to **Project Settings → Service Accounts**
3. Click **Generate new private key** — download the JSON file
4. Upload the JSON file to your server, e.g. `/var/www/your-forum/storage/fcm-service-account.json`
5. In the Flarum admin panel, set the **Service Account Path** to that file path

> The extension uses the FCM v1 API (`https://fcm.googleapis.com/v1/projects/{project_id}/messages:send`) with a short-lived OAuth2 access token generated from the service account. This is the only supported method since Google deprecated the legacy server key in 2024.

---

## Supported Notification Types

| Type | Description |
|------|-------------|
| `newPost` | New reply in a followed discussion |
| `postLiked` | Someone liked your post |
| `userMentioned` | You were mentioned with `@username` |
| `postMentioned` | Someone replied to your specific post |
| `newDiscussion` | New discussion created |
| `byobuPrivateDiscussionCreated` | New private message (FoF Byōbu) |
| `byobuPrivateDiscussionReplied` | Reply in a private message (FoF Byōbu) |
| `byobuPrivateDiscussionAdded` | You were added to a private conversation |
| `byobuRecipientRemoved` | You were removed from a private conversation |

> Other extensions that use Flarum's standard notification system will also trigger FCM pushes automatically. The `data.type` field will contain the notification type string.

---

## Notification Payload

Each push notification sent to the device contains:

```json
{
  "notification": {
    "title": "New reply",
    "body": "username in «Discussion title»",
    "sound": "default"
  },
  "data": {
    "type": "newPost",
    "discussion_id": "42",
    "post_id": "123"
  }
}
```

- `discussion_id` — always present when the notification relates to a discussion
- `post_id` — present for post-level notifications (replies, mentions, likes)

---

## Android Client Integration (Kotlin)

### Dependencies (`build.gradle.kts`)

```kotlin
implementation(platform("com.google.firebase:firebase-bom:33.x.x"))
implementation("com.google.firebase:firebase-messaging")
implementation("com.squareup.okhttp3:okhttp:4.x.x")
```

### Token Registration

Call this after the user logs in and whenever FCM issues a new token:

```kotlin
object FlarumApi {
    private val client = OkHttpClient()

    fun registerToken(context: Context, fcmToken: String) {
        val prefs = context.getSharedPreferences("flarum_prefs", Context.MODE_PRIVATE)
        val apiToken = prefs.getString("api_token", null) ?: return

        CoroutineScope(Dispatchers.IO).launch {
            val body = JSONObject()
                .put("token", fcmToken)
                .put("device_name", Build.MODEL)
                .toString()
                .toRequestBody("application/json".toMediaType())

            val request = Request.Builder()
                .url("https://your-forum.com/api/fcm/register")
                .post(body)
                .addHeader("Authorization", "Token $apiToken")
                .build()

            client.newCall(request).execute().close()
        }
    }

    fun unregisterToken(context: Context, fcmToken: String) {
        val prefs = context.getSharedPreferences("flarum_prefs", Context.MODE_PRIVATE)
        val apiToken = prefs.getString("api_token", null) ?: return

        CoroutineScope(Dispatchers.IO).launch {
            val body = JSONObject()
                .put("token", fcmToken)
                .toString()
                .toRequestBody("application/json".toMediaType())

            val request = Request.Builder()
                .url("https://your-forum.com/api/fcm/unregister")
                .delete(body)
                .addHeader("Authorization", "Token $apiToken")
                .build()

            client.newCall(request).execute().close()
        }
    }
}
```

### Obtaining the Flarum API Token

Intercept the login response in your WebView to capture the token:

```kotlin
// In WebViewClient.onPageFinished — inject JS to intercept XHR
webView.evaluateJavascript("""
    (function() {
        if (window._hooked) return; window._hooked = true;
        var orig = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function() {
            this.addEventListener('load', function() {
                try {
                    var d = JSON.parse(this.responseText);
                    if (d && d.token && d.userId) AndroidBridge.onLogin(d.token);
                } catch(e) {}
            });
            orig.apply(this, arguments);
        };
    })();
""", null)
```

```kotlin
class AndroidBridge(private val context: Context) {
    @JavascriptInterface
    fun onLogin(apiToken: String) {
        context.getSharedPreferences("flarum_prefs", Context.MODE_PRIVATE)
            .edit().putString("api_token", apiToken).apply()

        // Register the FCM token now that we have the API token
        FirebaseMessaging.getInstance().token.addOnSuccessListener { fcmToken ->
            FlarumApi.registerToken(context, fcmToken)
        }
    }
}
```

### Handling Notification Taps

```kotlin
class FcmService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        FlarumApi.registerToken(applicationContext, token)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val title = message.notification?.title ?: message.data["title"] ?: return
        val body  = message.notification?.body  ?: message.data["body"]  ?: ""

        val discussionId = message.data["discussion_id"]
        val postId       = message.data["post_id"]

        // Build the deep-link URL
        val url = when {
            discussionId != null && postId != null -> "https://your-forum.com/d/$discussionId#$postId"
            discussionId != null                   -> "https://your-forum.com/d/$discussionId"
            else                                   -> "https://your-forum.com"
        }

        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or
                    Intent.FLAG_ACTIVITY_CLEAR_TOP or
                    Intent.FLAG_ACTIVITY_NEW_TASK
            putExtra("url", url)
        }

        val pendingIntent = PendingIntent.getActivity(
            this, System.currentTimeMillis().toInt(), intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val channelId = "flarum_notifications"
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            manager.createNotificationChannel(
                NotificationChannel(channelId, "Forum notifications", NotificationManager.IMPORTANCE_DEFAULT)
            )
        }

        manager.notify(
            System.currentTimeMillis().toInt(),
            NotificationCompat.Builder(this, channelId)
                .setSmallIcon(R.drawable.ic_notification) // monochrome vector drawable
                .setContentTitle(title)
                .setContentText(body)
                .setAutoCancel(true)
                .setContentIntent(pendingIntent)
                .build()
        )
    }
}
```

### Logout

Unregister the token before clearing the session:

```kotlin
fun logout() {
    FirebaseMessaging.getInstance().token.addOnSuccessListener { token ->
        FlarumApi.unregisterToken(context, token)
    }
    // Clear stored API token
    getSharedPreferences("flarum_prefs", Context.MODE_PRIVATE)
        .edit().remove("api_token").apply()
}
```

---

## API Reference

### `POST /api/fcm/register`

Register a device token for the authenticated user.

**Headers:** `Authorization: Token <flarum_api_token>`

**Body:**
```json
{ "token": "fcm_device_token", "device_name": "Pixel 9" }
```

**Response:** `200 { "status": "ok" }`

---

### `DELETE /api/fcm/unregister`

Remove a device token (call on logout).

**Headers:** `Authorization: Token <flarum_api_token>`

**Body:**
```json
{ "token": "fcm_device_token" }
```

**Response:** `204 No Content`

---

## License

[MIT](LICENSE) — © SkrinVex
