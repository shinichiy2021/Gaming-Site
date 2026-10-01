/** Prefix for raw fetch() URLs; next/link already applies basePath. */
export const BASE_PATH = process.env.NEXT_PUBLIC_BASE_PATH ?? '';

export function withBase(path: string) {
  return `${BASE_PATH}${path}`;
}
