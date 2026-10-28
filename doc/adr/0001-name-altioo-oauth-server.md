# 0001 — The extension is named `altioo-oauth-server`

- Date: 2026-10-28
- Decided by: Guy Couronne
- Status: accepted

The extension that makes iTop its own OAuth 2.1 authorization server is named
`altioo-oauth-server`. The name says "server" so it is not confused with Combodo's
`combodo-oauth2-client`, which does the opposite job. The name is the
module code, so it is also the compiled directory and the issuer path
(`https://<itop>/env-production/altioo-oauth-server`) and the settings key
(`$MyModuleSettings['altioo-oauth-server']`). Changing it after release would change every
connected client's issuer.

Rejected: `altioo-oauth`, the working name. Too close to `combodo-oauth2-client`.

Enforced by: not machine-checkable yet, because no module file exists.
