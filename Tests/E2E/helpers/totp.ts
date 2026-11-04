import { generateSync } from 'otplib';

export function generateOtp(secret: string): string {
  return generateSync({ secret, strategy: 'totp' });
}

/** A 6-digit code that is guaranteed not to be the current one (current code + 1, wrapped). */
export function generateWrongOtp(secret: string): string {
  return ((Number(generateOtp(secret)) + 1) % 1_000_000).toString().padStart(6, '0');
}
