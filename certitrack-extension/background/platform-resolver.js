// Generic, configuration-driven platform matching. No platform names are hard-coded here:
// rules come from GET /api/platforms/config, so new platforms need no code changes.

export function parseHttpUrl(url) {
  try {
    const u = new URL(url);
    return u.protocol === 'http:' || u.protocol === 'https:' ? u : null;
  } catch {
    return null;
  }
}

export function domainMatches(u, d) {
  const host = u.hostname.toLowerCase();
  const rule = String(d.host).toLowerCase();
  const hostOk = d.match_type === 'exact' ? host === rule : host === rule || host.endsWith('.' + rule);
  if (!hostOk) return false;
  if (d.path_prefix) return u.pathname.startsWith(d.path_prefix);
  return true;
}

/** Is this URL inside one of the platform's approved domain rules? (query strings are ignored) */
export function isApproved(url, platform) {
  const u = parseHttpUrl(url);
  if (!u || !platform) return false;
  return (platform.domains || []).some((d) => domainMatches(u, d));
}

export function resolvePlatform(url, platforms) {
  return (platforms || []).find((p) => isApproved(url, p)) || null;
}
