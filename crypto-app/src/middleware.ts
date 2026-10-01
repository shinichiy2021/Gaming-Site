import { timingSafeEqual } from 'node:crypto';
import { NextResponse, type NextRequest } from 'next/server';

export const config = {
  runtime: 'nodejs',
  // '/' must be listed on its own or the basePath root skips the middleware.
  matcher: ['/', '/((?!_next/static|_next/image|favicon.ico).*)'],
};

function safeEqual(a: string, b: string) {
  const ab = Buffer.from(a);
  const bb = Buffer.from(b);
  return ab.length === bb.length && timingSafeEqual(ab, bb);
}

function unauthorized() {
  return new NextResponse('Authentication required', {
    status: 401,
    headers: { 'WWW-Authenticate': 'Basic realm="Portfolio", charset="UTF-8"' },
  });
}

export function middleware(req: NextRequest) {
  const user = process.env.CRYPTO_BASIC_AUTH_USER ?? '';
  const pass = process.env.CRYPTO_BASIC_AUTH_PASSWORD ?? '';

  if (!user || !pass) {
    // Production must never fall open when credentials are missing.
    if (process.env.CRYPTO_AUTH_REQUIRED === '1') {
      return new NextResponse('Portfolio auth is not configured', { status: 503 });
    }
    return NextResponse.next();
  }

  const header = req.headers.get('authorization') ?? '';
  if (!header.startsWith('Basic ')) return unauthorized();

  const decoded = Buffer.from(header.slice(6), 'base64').toString('utf8');
  const sep = decoded.indexOf(':');
  if (sep < 0) return unauthorized();

  const okUser = safeEqual(decoded.slice(0, sep), user);
  const okPass = safeEqual(decoded.slice(sep + 1), pass);
  return okUser && okPass ? NextResponse.next() : unauthorized();
}
