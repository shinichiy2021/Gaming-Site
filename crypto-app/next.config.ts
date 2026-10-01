import type { NextConfig } from 'next';
import path from 'path';

const basePath = process.env.CRYPTO_BASE_PATH || '';

const nextConfig: NextConfig = {
  output: 'standalone',
  outputFileTracingRoot: path.join( __dirname ),
  basePath: basePath || undefined,
  env: {
    NEXT_PUBLIC_BASE_PATH: basePath,
  },
};

export default nextConfig;
