=== INFO-Subscription Access ===
Contributors: infosoft
Tags: paywall, subscription, membership, restrict content, single sign-on
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.2.0
License: MIT
License URI: https://opensource.org/license/mit

Restrict articles to INFO-Subscription subscribers. Visitors sign in with INFO-Subscription; no WordPress user accounts needed.

== Description ==

INFO-Subscription Access connects a WordPress site to [INFO-Subscription](https://www.info-subscription.com/), the subscription platform from Infosoft. You need an INFO-Subscription tenant to use it.

Subscribers sign in through your INFO-Subscription sign-in page. The plugin verifies the returned ID token and reads the products the subscriber holds. Each article chooses who can read it:

* **AllAccess**: everyone.
* **UserLoggedIn**: any visitor signed in with INFO-Subscription.
* **SelectedProducts**: subscribers holding any one of the selected products.

Visitors without access see a short teaser with sign-in and subscribe links, or are redirected to your subscribe page.

Features:

* No WordPress accounts for subscribers, and no client secret for the subscriber sign-in.
* Editors pick products from a list loaded from the INFO-Subscription API, or type product IDs.
* Editors and administrators can always preview protected articles.
* Settings can be fixed in `wp-config.php` for staging and managed hosting.

== Installation ==

1. Upload the plugin through **Plugins > Add New Plugin**, or search for "INFO-Subscription Access", and activate it.
2. Open **Settings > INFO-Subscription**.
3. Under **Subscriber sign-in**, enter your tenant name and client ID. Ask Infosoft to register the redirect URI shown on the page for your sign-in application, which must allow ID tokens. Press **Test connection**.
4. Optional: under **Product list**, enter your INFO-Subscription tenant ID and API client ID, register the API redirect URI shown on the page as a public client (Mobile and desktop applications), then press **Sign in and load products**.
5. Edit an article and choose an access mode in the **Subscription access** box.

The site must use HTTPS.

== Frequently Asked Questions ==

= Do subscribers need a WordPress account? =

No. Subscriber sessions are kept separately from WordPress users.

= Does the plugin need a client secret? =

No. The subscriber sign-in uses the OpenID Connect ID token, and the administrator product sign-in uses PKCE. Tokens used to load products are not stored.

= What happens to articles that already existed? =

Articles without an access rule stay public.

= An article requires several products. Does a subscriber need all of them? =

No. Any one of the selected products gives access.

== Screenshots ==

1. A protected article before sign-in: visitors see a teaser with sign-in and subscribe links.
2. The same article after a subscriber with access signs in.

== External services ==

This plugin connects to services operated by Infosoft AS for INFO-Subscription. It does not work without an INFO-Subscription tenant.

**Subscriber sign-in (Azure AD B2C, `{tenant}.b2clogin.com`).** When a visitor chooses to sign in, they are sent to your tenant's sign-in page. The plugin sends the client ID, redirect URI, a random state and nonce. After sign-in, the service posts back an ID token containing the subscriber's identifier and products. The plugin also downloads the sign-in configuration and public signing keys to verify the token. The test connection button downloads the same configuration.

**Administrator product sign-in (`prodlogins4.b2clogin.com`).** Only when an administrator presses "Sign in and load products", the administrator is sent to the INFO-Subscription sign-in page, and the plugin exchanges the returned code for an access token.

**INFO-Subscription API (`api.info-subscription.com`).** Right after the product sign-in, the plugin requests the product list, sending the access token and your INFO-Subscription tenant ID. Product IDs, names and part numbers are stored in WordPress.

Terms and privacy policy: https://infosoft.as/privacy/

== Changelog ==

= 0.2.0 =
* After a password reset, the follow-up sign-in prefills the subscriber's email address.
* A clear message is shown if the password-reset sign-in cannot be completed.

= 0.1.0 =
* First release.
