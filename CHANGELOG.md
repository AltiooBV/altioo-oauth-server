# Changelog

All notable changes to this extension are recorded here, in the
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format. Versions follow
[semantic versioning](https://semver.org/). A version's date is the day its archive became
installable.

## [Unreleased]

Not released: what 0.1.0 will contain. Installed for testing on a staging instance; not yet
tested against claude.ai or any other real client.

### Added

- An OAuth 2.1 authorization server inside iTop: discovery (RFC 8414, RFC 9728) at the
  issuer's own `.well-known` paths, dynamic client registration (RFC 7591) for public clients,
  authorization through iTop's own login, consent, and a token endpoint for the
  `authorization_code` and `refresh_token` grants with PKCE S256.
- Access tokens are iTop `PersonalToken` objects, one per connection, renewed in place on
  every refresh (ADR 0004). Refresh tokens rotate, with a short grace period; reuse ends the
  connection, and of two refreshes with one token at the same moment only one rotates it.
- Clients created by an administrator, which may be confidential (`client_secret_basic`,
  `client_secret_post`), skip PKCE and name a default resource.
- Two resources: altioo-mcp and iTop's REST/JSON API, each with the profiles it requires,
  checked before consent, and the grades the consent page offers.
- A default allowlist of the main MCP clients' web callbacks (Claude, ChatGPT, Cursor, VS
  Code), with entries ending in `/*` for a callback per integration. It is checked at
  registration and again at every authorize, so removing an entry also refuses the clients that
  registered with it.
- Ending a connection: *Disconnect* under My Account, or under *Connected applications* in the
  portal's *My profile* for an account that has only the portal; *Revoke* in the console; and an
  hourly clean-up of connections past their lifetime, spent codes and unused clients.
- Requires iTop 3.2.1 or later, the first release with the portal's *My profile* hooks.
- `.htaccess` and `web.config` that serve only the entry point and the discovery paths, for
  GET, HEAD, POST and OPTIONS only.
