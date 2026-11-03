'use strict';

// Reads credentials from stdin and returns only a signed short-lived Knox token.
// Private key material is written to an OS temp file with owner-only permissions,
// then removed in a finally block. Never log keys, JWTs or access tokens.
const fs = require('fs');
const os = require('os');
const path = require('path');

function fail(message) {
  process.stdout.write(JSON.stringify({ success: false, error: String(message).slice(0, 700) }));
  process.exitCode = 1;
}

async function main() {
  let input = '';
  for await (const chunk of process.stdin) input += chunk;
  const payload = JSON.parse(input);
  if (!payload.clientId || !payload.keysJson) throw new Error('Client ID or keys.json is missing.');
  const keys = JSON.parse(payload.keysJson);
  if (!keys.Private || !keys.Public) throw new Error('keys.json must contain Private and Public properties.');

  const lib = require('knox-token-library-js');
  if (typeof lib.generateSignedClientIdentifierJWT !== 'function' ||
      typeof lib.generateSignedAccessTokenJWT !== 'function') {
    throw new Error('Samsung knox-token-library-js does not expose the documented signing functions.');
  }

  const tempDir = fs.mkdtempSync(path.join(os.tmpdir(), 'foneflex-knox-'));
  const keyPath = path.join(tempDir, 'keys.json');
  try {
    fs.writeFileSync(keyPath, JSON.stringify(keys), { mode: 0o600, flag: 'wx' });
    try { fs.chmodSync(keyPath, 0o600); } catch (_) { /* Windows ACLs govern access */ }

    const signedClientId = lib.generateSignedClientIdentifierJWT(keyPath, payload.clientId);
    const region = String(payload.region || 'eu').toLowerCase() === 'us' ? 'us' : 'eu';
    const endpoint = `https://${region}-kcs-api.samsungknox.com/ams/v1/users/accesstoken`;
    const response = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({
        base64EncodedStringPublicKey: keys.Public,
        clientIdentifierJwt: signedClientId,
        validityForAccessTokenInMinutes: 30
      })
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.accessToken) {
      const message = data.message || data.error || `Samsung access-token request failed (HTTP ${response.status}).`;
      throw new Error(message);
    }
    const signedToken = lib.generateSignedAccessTokenJWT(keyPath, data.accessToken);
    process.stdout.write(JSON.stringify({ success: true, token: signedToken }));
  } finally {
    try { fs.rmSync(tempDir, { recursive: true, force: true }); } catch (_) { /* best effort */ }
  }
}

main().catch((error) => fail(error && error.message ? error.message : 'Knox token generation failed.'));
