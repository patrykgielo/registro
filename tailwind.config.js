import daisyui from 'daisyui';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './resources/**/*.blade.php',
    './resources/**/*.js',
    './resources/**/*.vue',
  ],
  // Safelist for dynamically generated classes (CTA container padding/rounding)
  safelist: [
    'p-8', 'p-10', 'p-12',
    'md:p-12', 'md:p-16', 'md:p-20',
    'rounded-lg', 'rounded-xl', 'rounded-2xl', 'rounded-3xl',
  ],
  plugins: [
    typography,
    daisyui,
  ],
  daisyui: {
    themes: [
      {
        ios: {
          // Registro Brand Colors (Medical Precision: 24% Saturation Monochrome)
          "primary": "#4AA5B0",        // Turquoise accent (24% sat, from logo)
          "secondary": "#2B2D2F",      // Warm Charcoal
          "accent": "#4AA5B0",         // Same as primary (monochrome + accent)
          "success": "#34C759",        // iOS Green
          "warning": "#FF9500",        // iOS Orange
          "error": "#FF3B30",          // iOS Red
          "info": "#4AA5B0",           // Turquoise

          // Neutral Base Colors (Monochrome Luxury)
          "base-100": "#FFFFFF",       // Pure White
          "base-200": "#F3F4F6",       // Light Gray (neutral-100)
          "base-300": "#E5E7EB",       // Soft Gray (neutral-200)
          "base-content": "#1F2937",   // Charcoal Text (neutral-800)

          // Border radius (10px standard)
          "--rounded-box": "0.625rem",   // 10px for cards
          "--rounded-btn": "9999px",     // Pill buttons (preserve)
          "--rounded-badge": "0.625rem", // 10px for badges

          // Animations
          "--animation-btn": "0.3s",
          "--animation-input": "0.2s",

          // Borders
          "--border-btn": "1px",
        }
      }
    ],
    base: true,     // Apply base styles
    styled: true,   // Include component classes
    utils: true,    // Add utility classes
    logs: false,    // Disable console logs
  },
}
