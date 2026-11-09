# 0005 — 0.1.0 registers clients dynamically, without client ID metadata documents

- Date: 2026-11-02
- Decided by: Guy Couronne
- Status: accepted

The MCP authorization specification's latest revision (2026-07-28, read on 2026-11-02) says
clients and servers SHOULD support OAuth client ID metadata documents (CIMD,
draft-ietf-oauth-client-id-metadata-document-02, an active working-group draft). It keeps
dynamic client registration (RFC 7591) as a MAY, marked deprecated, for backwards
compatibility. A client tries its pre-registered credentials, then CIMD if the server
advertises `client_id_metadata_document_supported`, then dynamic registration, and may fall
back to it.

0.1.0 supports dynamic registration and clients an administrator creates, and not CIMD. It
does not advertise `client_id_metadata_document_supported`. The reasons:
- the specification asks it only as a SHOULD;
- dynamic registration is still allowed;
- Claude, the client this release is for, registered dynamically on itop.altioo.com on
  2026-10-29;
- CIMD would bring the module's first outbound request, which needs its own design.

Revisit this when a client this module targets stops falling back to dynamic registration, or
for 1.0, whichever comes first. The "for 1.0" half was answered on 2026-11-09 by
[ADR 0010](0010-client-id-metadata-documents-not-in-1-0.md): not in 1.0, with the conditions
that reopen it. Whoever builds it starts from these constraints:

- **An outbound fetch.** `doc/security-summary.md` then no longer says "Outbound connections:
  none", and the README's requirements name the outbound access the instance needs.
- **The draft's MUSTs for the fetch:**
  - no fetch of a URL that resolves to an RFC 6890 special-use address, outside a loopback test
    setup, and so a check of the resolved address, which the draft leaves to the server;
  - no redirects followed;
  - only a `200` accepted;
  - no caching of an error or of an invalid document;
  - the document's `client_id` equal to the URL, compared as plain strings;
  - redirect URIs matched exactly against the document's.
- **Its SHOULDs:** read at most about 5 KB, honour cache headers, show the `client_id`'s host on
  the consent page.
- **What the draft leaves open:** timeouts, rate limits and DNS rebinding. They are this
  module's to design.
- **Public clients only.** The draft forbids shared secrets in a document. A CIMD client is
  bound by PKCE, as a self-registered one is today.
- **The administrator stays the allowlist.** Anyone can host a document, so the consent page's
  host would no longer be one the administrator chose. An allowlist of trusted `client_id` URLs
  or hosts, beside `redirect_uris`, keeps that protection.

Rejected: CIMD in 0.1.0. That would add an outbound fetch and its SSRF defences to an
unreleased first increment, for no client that needs it yet.

Enforced by: `tools/ci/checks/http-smoke.sh`, which fails if the server metadata names
`client_id_metadata_document_supported`. That is the decision's one binary part: the rest,
which clients still fall back, is not machine-checkable.
