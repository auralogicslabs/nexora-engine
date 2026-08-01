/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',
  content: ['./frontend/**/*.{tsx,ts,jsx,js}'],
  theme: {
    extend: {
      colors: {
        // ── Nexora Engine — Deep Blue Infrastructure palette ──
        // Source of truth: matches existing assets/css/admin.css --ncx-primary-* scale.
        blue: {
          50:  '#E8EFFF',
          100: '#D1DFFF',
          200: '#A8C2FE',
          300: '#7AA4FC',
          400: '#56A2FA',
          500: '#0252FA',  // brand primary
          600: '#063CE6',  // brand deep
          700: '#0430C4',
          800: '#0228A2',
          900: '#011E80',  // sidebar dark
          950: '#010E40',  // sidebar darker
        },
        // Sky CTA accent (for secondary actions on dark sidebar)
        sky: {
          50:  '#F0F9FF',
          100: '#E0F2FE',
          200: '#BAE6FD',
          300: '#7DD3FC',
          400: '#38BDF8',
          500: '#0EA5E9',
          600: '#0284C7',
          700: '#0369A1',
          800: '#075985',
          900: '#0C4A6E',
        },
        // Warm off-white background
        cream: {
          50:  '#FBFAF7',
          100: '#F6F4ED',
          200: '#EFEBDE',
          300: '#E2DCC8',
          400: '#CFC6A8',
          500: '#B8AC87',
        },
        // Legacy aliases so cards/buttons that say "pulse" still work
        pulse: {
          50:  '#E8EFFF',
          100: '#D1DFFF',
          200: '#A8C2FE',
          300: '#7AA4FC',
          400: '#56A2FA',
          500: '#0252FA',
          600: '#063CE6',
          700: '#0430C4',
          800: '#0228A2',
          900: '#011E80',
          950: '#010E40',
        },
      },
      fontFamily: {
        sans: ['"Plus Jakarta Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'ui-monospace', 'monospace'],
      },
      borderRadius: {
        'xl':  '14px',
        '2xl': '18px',
        '3xl': '24px',
      },
      boxShadow: {
        'np-card':       '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 6px 0 rgb(15 23 42 / 0.04)',
        'np-card-hover': '0 4px 12px -2px rgb(15 23 42 / 0.08), 0 2px 6px -1px rgb(15 23 42 / 0.05)',
        'np-modal':      '0 24px 80px -12px rgb(15 23 42 / 0.30), 0 8px 24px -6px rgb(15 23 42 / 0.15)',
      },
      animation: {
        'fade-in':  'fadeIn 0.2s ease-out',
        'slide-up': 'slideUp 0.25s ease-out',
      },
      keyframes: {
        fadeIn:  { from: { opacity: '0' }, to: { opacity: '1' } },
        slideUp: { from: { opacity: '0', transform: 'translateY(8px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
      },
    },
  },
  plugins: [require('@tailwindcss/forms')],
};
