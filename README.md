# Altioo OAuth Server — iTop as its own OAuth 2.1 authorization server

Lets Claude, and any other OAuth client, connect to an iTop instance through the
standard "Connect" button, with no token copied by hand. A person signs in to iTop the way they
always do, sees who is asking and for what, and approves. The client then gets an ordinary
iTop personal token that expires on its own and is renewed while the connection lasts. The
person can see the connection, and end it, under *My Account*.

[Security](SECURITY.md) · [Contributing](CONTRIBUTING.md) · [Decisions](doc/adr/) ·
[Settings](#settings) · [Troubleshooting](#troubleshooting)

> **Not released yet.** Version 0.1.0 is the first increment. It has not been tested against
> claude.ai, which the release gate requires.

## What it protects

Two resources ship in the first release, each declared in the module's `resources` setting
([ADR 0003](doc/adr/0003-generic-from-the-first-release.md)):

- **[altioo-mcp](https://github.com/AltiooBV/altioo-mcp)**, the MCP server for iTop. This is
  what Claude's connector reaches. It is offered only where altioo-mcp is installed.
- **iTop's REST/JSON API** (`webservices/rest.php`), for a client configured by hand (below),
  or one given this server's metadata URL: `rest.php` does not announce the server, so no
  client finds it on its own. It reads the token from an `Auth-Token` header or an
  `auth_token` parameter, not from `Authorization: Bearer`, so the client must be able to send
  one of those.

The tokens it issues are the same `PersonalToken` objects iTop's `authent-token` module already
manages. altioo-mcp and `rest.php` therefore check them exactly as they check a token made by
hand. They still answer only for what the person could do in the console.

## Which clients can connect

Two kinds, and the administrator decides which of each may connect.

**Clients that connect themselves.** These follow the [MCP authorization
specification](https://modelcontextprotocol.io/specification/latest/basic/authorization):
they discover the server from the resource, register themselves, and use the authorization code
flow with PKCE. Nothing is typed by hand, but a client registers only if it sends people back
to a redirect URI on the `redirect_uris` allowlist.

The default allowlist holds the published web callbacks of the main MCP clients. None of them
has been tested end to end here yet, and vendors change these: check the client's own
documentation, or the refusal in iTop's log (*Troubleshooting*), which names the URI a client
asked for.

| Client | Redirect URI | In the default allowlist | Where it comes from |
|---|---|---|---|
| ChatGPT | `https://chatgpt.com/connector_platform_oauth_redirect`, or per integration `https://chatgpt.com/connector/oauth/{callback_id}` | Both (the second as `.../connector/oauth/*`) | [OpenAI, Authentication](https://developers.openai.com/plugins/build/auth): the first when the server sends `iss`, which this one does |
| Claude (web, Desktop, mobile) | `https://claude.ai/api/mcp/auth_callback`, `https://claude.com/api/mcp/auth_callback` | Both | [Zuora's list of MCP client callbacks](https://docs.zuora.com/en/zuora-ai/zuora-mcp-server/zuora-mcp-server/configure-zuora-remote-mcp-server/redirect-uris-by-mcp-client); the first was this module's target from the start |
| VS Code | Web `https://vscode.dev/redirect` (a forwarder — see below); desktop `http://127.0.0.1:33418/` | Web; desktop with `allow_loopback` | [Zuora's list of MCP client callbacks](https://docs.zuora.com/en/zuora-ai/zuora-mcp-server/zuora-mcp-server/configure-zuora-remote-mcp-server/redirect-uris-by-mcp-client) |
| Gemini CLI | `http://localhost:7777/oauth/callback` | With `allow_loopback` | Same list; the port can be configured |
| Cursor | Web and agents `https://www.cursor.com/agents/mcp/oauth/callback`; desktop `http://localhost:8787/callback` | Web; desktop with `allow_loopback` | Same list |
| Claude Code | `http://localhost:{port}/callback` | With `allow_loopback`; it also needs the site-root discovery rules below | Same list; the root lookup seen on itop.altioo.com, 2026-10-29 |
| Grok | Not published. One unconfirmed report names `console.x.ai/connectors-oauth-exchange-code/` | No | Find it in the log, then add it |
| Mistral Le Chat | Not published | No | Find it in the log, then add it |

Remove the entries this instance does not serve: each one is a callback anyone using that
product can register a client with, and the consent page, which names the host, is then what
stands between a phishing link and a person's iTop. Removing an entry also stops the clients
that registered with it: authorize refuses them from then on. Connections they already made
keep refreshing, since a refresh sends nobody anywhere; revoke them under *OAuth connections*
if the callback has to go at once.

One default entry needs more than that. `https://vscode.dev/redirect` is not a final callback:
VS Code web lands the code on that page, which then forwards it to a target VS Code chose — a
loopback port or a `vscode://` handler. So for this one entry the host the consent page shows,
`vscode.dev`, is not where the code finally lands; vscode.dev's own allowlist is what decides
that. Keep `https://vscode.dev/redirect` only while VS Code web actually connects here, and
remove it otherwise — a forwarder on the allowlist is worth more to someone crafting a link than
an ordinary callback is.

**Clients configured by hand ("classic" OAuth).** Many agent platforms let you set up an OAuth
integration yourself: Grok, ChatGPT, DeepSeek, Gemini, Mistral and others, in no particular
order. Whether a given platform offers one, for which feature and on which plan, is for that
platform to say; none has been tested here. They all ask for the same things and give back a
callback URL:

1. In iTop, *Admin tools*, *OAuth clients*, create a client:
   - **Redirect URIs:** the callback URL the platform shows, one per line, exactly as shown.
   - **Authentication:** *Secret, in an HTTP Basic header*, unless the platform says it sends
     the secret in the request body.
   - **Client secret:** a long random value, for example from `openssl rand -base64 32`. It is
     stored as a one-way hash, so copy it before you save.
   - **PKCE:** *Optional* if the platform does not send it, otherwise leave *Required*.
   - **Default resource:** `rest` for REST/JSON, or `mcp`. A classic client does not say which
     resource it wants.
2. Save, and copy the generated *Client ID*.
3. On the platform, enter:

| Field | Value |
|---|---|
| Authorization URL | `https://<itop>/env-production/altioo-oauth-server/index.php?route=authorize` |
| Token URL | `https://<itop>/env-production/altioo-oauth-server/index.php?route=token` |
| Client ID and secret | From step 1 and 2 |
| Scope | The scopes to ask for, separated by spaces: `REST/JSON`, or for altioo-mcp `MCP-read`, `MCP-write`... The person can still grant less on the consent page. |

A client created this way skips the `redirect_uris` allowlist: the administrator who created it
is the allowlist. Clients that register through a client ID metadata document (CIMD) are not
supported yet, although the MCP specification's latest revision prefers them to dynamic
registration, which it keeps for backwards compatibility. A client that offers only CIMD cannot
connect itself here ([ADR 0005](doc/adr/0005-no-client-id-metadata-documents-in-0-1.md)).

## How a connection is made

1. The client finds the server from the resource's metadata (RFC 9728) and the server's own
   (RFC 8414). It then registers itself (RFC 7591), but only to send people back to an
   address on the allowlist.
2. It sends the person to iTop's sign-in page. Every login mode and MFA policy the instance
   has applies; this module adds no way around them.
3. The consent page shows the client, where the person will be sent back, and what the
   connection may do. The client's name is chosen by the client, and the page says so.
4. The client exchanges a single-use code, bound by PKCE, for an access token and a refresh
   token. The access token is a `PersonalToken` that expires after an hour. On each refresh
   the same token gets a new value, and the previous value stops working
   ([ADR 0004](doc/adr/0004-one-personal-token-per-grant.md)). The refresh token is replaced
   on every use, and presenting an old one after a short grace period ends the connection.

A connection ends after `grant_idle_days` without use, or `max_grant_days` after it was made,
whichever comes first. The person then signs in again.

## Which accounts can connect

Any account that can sign in to iTop, console or portal, provided authent-token lets it own
personal tokens (`personal_tokens_allowed_profiles`) and the resource accepts it: for altioo-mcp,
a profile in its `mcp_allowed_profiles`, and for a portal account its portal mode; for
REST/JSON, the *REST Services User* profile. Otherwise the consent page is replaced by a page
saying so.

## For a protected resource: which resource a token is for, and where its metadata is

authent-token does not know which resource a token was granted for. A resource that is one
endpoint told apart by a query (one per portal, say) would therefore accept, at one URL, a token
a person granted for the other. The resource can ask this module, and refuse it:

```php
use Altioo\iTop\Extension\OAuthServer\TokenAudience;

if (class_exists(TokenAudience::class)) { // this module may not be installed
	$oAudience = TokenAudience::Of($iPersonalTokenId);
	if ($oAudience !== null && !$oAudience->IsFor($sUrlCalled)) {
		// Granted for $oAudience->sResourceUrl, not for this URL: refuse the call.
	}
}
```

- `Of()` answers `null` for a token no connection owns, such as one an administrator made by
  hand. Whether to accept that token is the resource's own rule.
- `IsFor()` compares exactly, as the authorization request's `resource` did, so build
  `$sUrlCalled` the way the `resources` setting spells the URL. A token whose resource has been
  removed from `resources` is for no URL.
- `sResourceKey` is the resource's key in `resources`.

A resource finds the metadata to advertise in its `401` the same way, from the URL it was
called at, so that a client is sent to consent for that resource and not for another on the same
endpoint:

```php
use Altioo\iTop\Extension\OAuthServer\ResourceMetadata;

$sMetadata = class_exists(ResourceMetadata::class) ? ResourceMetadata::UrlFor($sUrlCalled) : null;
// null: no resource has that URL. Otherwise:
// WWW-Authenticate: Bearer resource_metadata="$sMetadata"
```

`TokenAudience` and `ResourceMetadata` are this module's public API (`@api`): their names,
`Of()`, `IsFor()`, `UrlFor()` and `TokenAudience`'s two properties keep their meaning across
minor versions.

## Ending a connection

- **The application**, when it signs out or is removed, if it revokes its refresh token or
  its access token (RFC 7009) at `route=revoke`, which the server metadata advertises. Either
  ends the whole connection, with the reason *Ended by the application*.
- **The person**, under *My Account*, *Personal Tokens*. The *Connected applications* section
  lists each connection with *Disconnect*. Deleting the connection's token in the list below it
  ends the connection too. In the portal, the same list is the *Connected applications* tab of
  *My profile*. Both appear only to an account allowed personal tokens.
- **An administrator**, under *Admin tools*, *OAuth connections*: open the connection and use
  *Revoke*. Disabling a client under *OAuth clients* ends each of its connections at its next
  refresh, and refuses new ones. Deleting a connection, or a client with its connections, ends
  them and deletes their tokens too, but also deletes the record of them that *Revoke* and
  disabling keep.
- **iTop itself**, when the account is disabled, or no longer allowed personal tokens, or no
  longer holds a profile the resource requires, or has gained or lost the console since the
  connection was made: the next refresh fails and the connection ends.
- **All of one person's connections at once:** *OAuth connections*, search on the user, then
  *Revoke* each; or disable the iTop user, which ends each connection at its next refresh, at
  most `access_token_lifetime` later.

Lowering `max_grant_days` or `grant_idle_days`, or narrowing a resource's scopes, applies to
connections already made, from their next refresh.

Every way above deletes the connection's token, so the application loses access at once. The
one exception is disabling a client: its tokens keep working until they expire, at most
`access_token_lifetime` later.

**With an identity provider in front of iTop** (SAML, OpenID Connect, LDAP): disabling a person
there does not disable their iTop user. Their connections keep working until the next limit is
reached (`grant_idle_days` or `max_grant_days`), or until the iTop user is disabled. Set
`max_grant_days` to what your offboarding can tolerate, and disable the iTop user as part of it.
A lower `max_grant_days` takes effect at each connection's next refresh, at most
`access_token_lifetime` later.

## Requirements

| | |
|---|---|
| iTop and PHP | iTop 3.2.1 or later, within what [`.github/itop-support.json`](.github/itop-support.json) declares, which is what CI installs on. 3.2.0 lacks the hooks the portal tab under *My profile* is built on. |
| iTop modules | `authent-token` 2.2.1 or later, which ships with iTop |
| Login mode | `token` in `allowed_login_types`, as for any personal token |
| HTTPS | Required. OAuth over plain HTTP hands every code and token to the network. |
| Network | The client's servers must reach iTop. For Claude, that is Anthropic's addresses. |
| Cron | iTop's `webservices/cron.php` running, for the hourly clean-up. Without it, a connection past its lifetime keeps its token until its client next tries to refresh. |
| Web server | Apache with `AllowOverride All` and `mod_rewrite`, for the module's `.htaccess`; or IIS with the URL Rewrite module, for its `web.config` |
| Client address | Behind a reverse proxy or load balancer, the web server must hand PHP the caller's address: Apache's `mod_remoteip`, nginx's `real_ip` module, or IIS ARR's forwarded address. The module reads `REMOTE_ADDR` and nothing else. Otherwise `registration_rate_limit` counts every caller as one address, and the log and the connections name the proxy. |
| REST/JSON | A person reaches `rest.php` with a token only with `allow_rest_services_via_tokens` set, or the *REST Services User* profile |

## Installation

1. **Back up** the database and the iTop directory: setup rewrites `env-production/`.
2. Unpack the archive into `extensions/`, so that `extensions/altioo-oauth-server/` holds this
   README.
3. Run setup, *Upgrade an existing iTop instance*, and tick the extension. A file drop alone
   changes nothing: iTop runs the compiled copy. Setup adds three tables.
4. Check that `https://<itop>/env-production/altioo-oauth-server/.well-known/openid-configuration`
   answers with JSON. This is the issuer's discovery document.
5. For altioo-mcp: nothing to do from its 1.2.0, which finds this server when it is installed
   and advertises it in the challenge of its 401 (and stops when it is removed). With an older
   altioo-mcp, set its `mcp_protected_resource_metadata` setting to
   `https://<itop>/env-production/altioo-oauth-server/index.php?route=protected-resource&for=mcp`.
   That is how Claude finds this server from the MCP endpoint.

Then, in Claude, add a custom connector with the altioo-mcp endpoint URL, exactly
`https://<itop>/env-production/altioo-mcp/index.php`, and choose *Connect*. Another spelling of
it (through `extensions/`, or without `index.php`) is not the resource this server knows, and
the iTop page says "Unknown resource".

### What the module's `.htaccess` and `web.config` allow

Only `index.php` and the two discovery paths under `.well-known/`, and only the methods GET,
HEAD, POST and OPTIONS. Everything else in the module's directory is refused: `src/`,
`vendor/`, `templates/`, `tests/`, the dictionaries, `composer.json` and the two files
themselves. On Apache this was checked on 2.4.69 with a vhost that also denies every dotfile,
as hardened setups do. On IIS, the discovery paths have no extension, so `web.config` lets
extensionless requests through under `.well-known/` only, where nothing exists on disk; this
has not been tried on an IIS server yet.

### Discovery at the site root

The module serves its metadata inside the issuer's path
(`<issuer>/.well-known/openid-configuration`), which is where Claude looks. Others follow
RFC 8414 and RFC 9728 to the letter and look at the site root, with the path appended: Claude
Code does, and gets a 404 there without these rules. A module cannot answer at the site root, so
add them to the site configuration (on Apache, in the vhost, with `mod_rewrite`):

```apache
RewriteEngine On
# The authorization server's metadata, RFC 8414 and its OpenID twin.
RewriteRule ^/?\.well-known/(oauth-authorization-server|openid-configuration)/env-production/altioo-oauth-server$ /env-production/altioo-oauth-server/index.php?route=$1 [PT,QSA,L]
# altioo-mcp's protected resource metadata, RFC 9728, for clients that do not read it from the
# endpoint's 401.
RewriteRule ^/?\.well-known/oauth-protected-resource/env-production/altioo-mcp/index\.php$ /env-production/altioo-oauth-server/index.php?route=protected-resource&for=mcp [PT,QSA,L]
```

`PT` hands the result back to Apache as a request for that file, so PHP and the module's
`.htaccess` handle it like any other.

```nginx
location ~ ^/\.well-known/(oauth-authorization-server|openid-configuration)/env-production/altioo-oauth-server$ {
	rewrite ^ /env-production/altioo-oauth-server/index.php?route=$1 last;
}
location = /.well-known/oauth-protected-resource/env-production/altioo-mcp/index.php {
	rewrite ^ /env-production/altioo-oauth-server/index.php?route=protected-resource&for=mcp last;
}
```

On nginx, the module's `.htaccess` does not apply either. Deny everything under
`env-production/altioo-oauth-server/` except `index.php`, and rewrite its two `.well-known`
paths to `index.php?route=...` the same way.

## Settings

In the configuration file, under `$MyModuleSettings['altioo-oauth-server']`.

| Setting | Default | Effect |
|---|---|---|
| `redirect_uris` | The web callbacks in the table under *Which clients can connect* | The only redirect URIs a client may register itself with, matched exactly. An entry ending in `/*` matches one more path segment, for a client with a callback per integration. Checked again at every authorize, so removing an entry also refuses the clients that registered with it. |
| `allow_loopback` | `false` | Also accept `http://localhost`, `127.0.0.1` and `[::1]` on any port, for local clients. Turning it off refuses the local clients already registered too. |
| `registration_rate_limit` | `300` | Registrations accepted from one IP address per hour. Every person connecting Claude registers from Anthropic's addresses, so keep it well above the number of people who may connect in an hour. |
| `unused_registration_limit` | `1000` | Registrations kept that have not completed a connection yet, from all addresses together. Past it a new registration is still accepted, and the oldest unused one is deleted to make room, unless a person has already consented to it. A flood of registrations therefore never stops anyone from connecting, but a large one can push out a registration whose person had not consented yet; they then start again. |
| `allowed_origins` | empty | Origins allowed to read the registration, token and revocation responses from a browser, for browser-based clients such as the MCP Inspector. Empty sends no CORS header there, which is what Claude needs. The discovery documents are readable from any origin whatever this says: they are public, and sent without credentials. |
| `code_lifetime` | `60` | Seconds an authorization code is valid |
| `access_token_lifetime` | `3600` | Seconds an access token is valid before the client refreshes it |
| `refresh_grace_seconds` | `30` | How long a replaced refresh token is still accepted, so that a lost response does not end a connection |
| `grant_idle_days` | `30` | A connection unused this long ends |
| `max_grant_days` | `90` | A connection ends this long after it was made, whatever happens |
| `ended_grant_retention_days` | `90` | How long an ended connection is kept as a record |
| `resources` | empty: altioo-mcp and REST/JSON | The resources a token may be granted for (below) |

`resources` maps a short key to a resource:

```php
'resources' => [
	'mcp' => [
		'url' => 'https://itop.example.com/env-production/altioo-mcp/index.php',
		'label' => 'iTop through altioo-mcp', // what the consent page and the connection lists call it; empty shows the URL
		'bearer_methods' => ['header'],       // how it reads a token (RFC 9728); [] if not Authorization: Bearer
		'scope_prefix' => 'MCP',              // every PersonalToken scope starting with this
		'default_scopes' => ['MCP-read'],     // pre-ticked on the consent page
		'offered_scopes' => [],               // empty: the whole family; otherwise only these
		'forced_scopes' => ['MCP-advisory'],  // always added, whatever the person ticks
		'required_profiles' => ['Administrator', 'MCP Services User'], // refused before consent without one
		'grades' => [                         // radio buttons instead of a checkbox per scope
			['label' => 'UI:AltiooOAuthServer:Grade:Read', 'scopes' => ['MCP-read']],
			['label' => 'UI:AltiooOAuthServer:Grade:Write', 'scopes' => ['MCP-write']],
		],
		'limits' => [                         // unticked checkboxes that narrow the grade chosen
			['label' => 'Only the tools that read and write objects', 'scopes' => ['MCP-toolset-objects']],
		],
		'portal_grades' => [                  // what an account without the console is offered instead
			['label' => 'UI:AltiooOAuthServer:Grade:Read', 'scopes' => ['MCP-read']],
			['label' => 'UI:AltiooOAuthServer:Grade:PortalAll', 'scopes' => ['MCP']],
		],
	],
],
```

The defaults: for altioo-mcp, its `mcp_allowed_profiles` as `required_profiles`; the read,
write, delete, `MCP` and `MCP-advisory` scopes as `offered_scopes`; four grades (read only;
read and change; read, change and delete; everything); for an account without the console,
two portal grades (read only; everything I can do in my portal); and one limit, `MCP-advisory`,
worded as "only propose changes, never make them". For REST/JSON, the *REST Services User* profile
when `rest.php` requires it (`secure_rest_services` on and `allow_rest_services_via_tokens`
off), and no bearer method, since `rest.php` does not read `Authorization: Bearer`. Each
default has a label in the dictionary. A `label` is a dictionary key or plain text.
`bearer_methods` is `['header']` when left out.

`portal_grades`, when set, replaces `grades` for an account that has only the portal, and that
account is offered nothing else but the `limits`: no checkbox for a scope outside them. It is
for a resource that tells fewer things apart for such an account. altioo-mcp's portal mode
serves what the stricter of the account's profiles and its portal allows, and has no delete,
so the choice there is to read, or to do all of that. What "all" covers then follows the
account's profiles and portal at every call, with no new consent. A connection is held to the
kind of account it was made for: one made with the portal only ends at its next refresh once the
account has the console, and one made with the console ends once the account has only the
portal. The person then connects again, and is offered that kind's grades.

A scope that restricts a token rather than allowing more, such as altioo-mcp's
`MCP-toolset-*`, belongs in `limits`. Offered any other way it is a checkbox under "And, if you
want:", where a person reads it as something more to allow. That is why the default offer
leaves the toolsets out. A limit is offered whatever the client asks for, since it only ever
grants less, and it cannot be granted on its own: a person who ticks a limit and nothing else
is told that nothing was selected. Nor is it kept on its own: a connection that a narrower
offer leaves with nothing but limits ends at its next refresh, since a resource may read a
token holding only limits as one that names no grade at all.

`url` must be the resource's URL exactly as clients send it. It may carry a query when the
query is what tells two resources apart (RFC 8707 allows it): altioo-mcp's `?portal_id=`, for
example, makes each portal a resource of its own, with a key, a `label` naming the portal and
grades of its own. Setting `resources` replaces both defaults, so list every resource to keep.

The `label` names the resource on the consent page, under *Connected applications* (My Account
and the portal's *My profile*), and under *OAuth connections*, where its key follows it. The
consent page gives the URL under it too, since the URL, not the label, is what the token is for.

## What it adds

| | |
|---|---|
| Classes | `AltiooOAuthClient`, `AltiooOAuthGrant` (a connection) and `AltiooOAuthCode`, visible to Administrators only. The prefix is there because iTop's own OAuth client module already has an `OAuthClient` class. |
| Menus | *Admin tools*, *OAuth clients* and *OAuth connections* |
| My Account | A *Connected applications* section under *Personal Tokens* |
| Portal | A *Connected applications* tab under *My profile*, which posts *Disconnect* back to that page |
| Endpoints | `env-production/altioo-oauth-server/index.php`, with `route=` `openid-configuration`, `oauth-authorization-server`, `protected-resource`, `register`, `authorize`, `token`, `revoke` and `disconnect`; and the two `.well-known` paths above |
| PHP API | `TokenAudience` and `ResourceMetadata`, for a protected resource to ask which resource a token was granted for, and where its metadata is (above) |
| Background process | Hourly: ends connections past their lifetime, deletes spent codes, old ended connections, and clients registered but never used for 24 hours |
| Profiles | None |

## Data stored

- **Clients:** the name, the redirect URIs, the IP address the registration came from.
- **Connections:** who made them, for which client and resource, with which scopes; when they
  were made, last renewed, and from which IP address; how they ended. Kept for
  `ended_grant_retention_days` after they end.
- **Secrets:** codes and refresh tokens, stored as one-way hashes only. Nothing in the database
  can be used as a credential. The access token is authent-token's `PersonalToken`, stored the
  way authent-token stores it.

The token's change history names the client and records that this module made the change.

## Uninstall

Remove the module through setup. Its tables (`priv_altioo_oauth_client`,
`priv_altioo_oauth_grant`, `priv_altioo_oauth_code`) stay, as with any iTop module. Before you
uninstall, revoke every connection, or delete the personal tokens whose application starts
with `OAuth:`: they would otherwise keep working until they expire, at most
`access_token_lifetime` later, without anything to renew them. Dropping the tables afterwards
cannot be undone.

## Troubleshooting

The module logs on the `altioo-oauth-server` channel of iTop's error log, with iTop's default
log level: unexpected errors (Error), every refusal with its reason and the caller's address
(Warning), and one line per clean-up run (Ok). No code, token, secret or refresh value is ever
logged. Raise the level for this channel alone with
`'log_level_min' => ['altioo-oauth-server' => 'Debug']` in the configuration file.

| Symptom | Usual cause |
|---|---|
| The client says the server cannot be found | The discovery document does not answer: `AllowOverride` is off, the URL Rewrite module is missing on IIS, or the client looks at the site root (see above). |
| "Unknown application" on the iTop page | The client's redirect URI is not on `redirect_uris`, or its registration was deleted as unused: after 24 hours, or sooner to make room past `unused_registration_limit`, which leaves the log line `Unused registration limit reached`. Many of those lines in a row are a flood of registrations: refuse the addresses they name at the web server. |
| The client says it cannot register | Its callback is not on `redirect_uris`. The log line `Refused on route register: invalid_redirect_uri (this server does not allow the redirect URI ...)` names it: add it, exactly, if the host is the client's own. |
| The client cannot register, and the log says `too many registrations from this address` | `registration_rate_limit` was reached for that address, which the log line gives. If it is Anthropic's, raise the limit: every person connecting Claude registers from there. |
| "Personal tokens not allowed" | The person's profiles are not in authent-token's `personal_tokens_allowed_profiles`. |
| Connected, but every call fails | `token` is missing from `allowed_login_types`, or the resource refuses the person (altioo-mcp's profiles, `rest.php`'s REST Services User). |
| The client asks to reconnect every day | `grant_idle_days` or `max_grant_days` is short, or the person or an administrator ended the connection. *OAuth connections* shows why it ended. |

## Licence

Free, under [AGPL-3.0-or-later](LICENSE), like altioo-mcp. There is no paid edition and no
licence check ([ADR 0002](doc/adr/0002-free-under-agpl-3.md)).

## Support

Report bugs and request features through GitHub issues. To report a vulnerability, write to
<security@altioo.com> or follow [SECURITY.md](SECURITY.md). Never open a public issue for one.
