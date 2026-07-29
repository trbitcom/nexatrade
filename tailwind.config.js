/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './app/Views/**/*.php',
  ],
  // hover: siniflari sadece gercekten hover destegi olan (fare/trackpad) cihazlarda
  // aktif olsun - aksi halde dokunmatik ekranlarda dokunulan bir eleman, parmak
  // cekilene kadar "hover" halinde takili kalirdi (ozellikle butonlar/satirlarda fark edilir)
  future: {
    hoverOnlyWhenSupported: true,
  },
  theme: {
    extend: {
      fontFamily: {
        display: ['"Space Grotesk"', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'monospace'],
      },
      keyframes: {
        blink: { '0%, 50%': { opacity: 1 }, '51%, 100%': { opacity: 0 } },
      },
      animation: {
        blink: 'blink 1.2s steps(1) infinite',
      },
    },
  },
  plugins: [],
};
