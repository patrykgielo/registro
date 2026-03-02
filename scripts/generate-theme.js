#!/usr/bin/env node

/**
 * Generate Tailwind 4.0 @theme {} from design-system.json
 *
 * This script reads iOS design tokens from design-system.json and generates
 * CSS custom properties compatible with Tailwind CSS 4.0 @theme {} syntax.
 *
 * Single Source of Truth: design-system.json
 * Output: resources/css/design-tokens.css
 *
 * Usage:
 *   node scripts/generate-theme.js
 *   npm run generate:theme
 */

import { readFileSync, writeFileSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// Paths
const DESIGN_SYSTEM_PATH = resolve(__dirname, '../design-system.json');
const OUTPUT_PATH = resolve(__dirname, '../resources/css/design-tokens.css');

/**
 * Convert design-system.json colors to CSS custom properties
 */
function generateColorVariables(colors) {
  const lines = [];

  // Primary colors
  if (colors.primary) {
    Object.entries(colors.primary).forEach(([key, value]) => {
      lines.push(`  --color-primary-${key}: ${value};`);
    });
  }

  // Secondary colors
  if (colors.secondary) {
    Object.entries(colors.secondary).forEach(([key, value]) => {
      lines.push(`  --color-secondary-${key}: ${value};`);
    });
  }

  // Semantic colors
  if (colors.semantic) {
    Object.entries(colors.semantic).forEach(([key, value]) => {
      lines.push(`  --color-${key}: ${value};`);
    });
  }

  // Background colors
  if (colors.background) {
    Object.entries(colors.background).forEach(([key, value]) => {
      const cssKey = key.replace(/([A-Z])/g, '-$1').toLowerCase();
      lines.push(`  --color-bg-${cssKey}: ${value};`);
    });
  }

  // Text colors
  if (colors.text) {
    Object.entries(colors.text).forEach(([key, value]) => {
      lines.push(`  --color-text-${key}: ${value};`);
    });
  }

  // Gray scale
  if (colors.gray) {
    Object.entries(colors.gray).forEach(([key, value]) => {
      lines.push(`  --color-gray-${key}: ${value};`);
    });
  }

  // Dark theme colors
  if (colors.dark) {
    Object.entries(colors.dark).forEach(([key, value]) => {
      const cssKey = key.replace(/([A-Z])/g, '-$1').toLowerCase();
      lines.push(`  --color-dark-${cssKey}: ${value};`);
    });
  }

  return lines;
}

/**
 * Convert typography tokens to CSS custom properties
 */
function generateTypographyVariables(typography) {
  const lines = [];

  // Font families
  if (typography.fontFamilies) {
    Object.entries(typography.fontFamilies).forEach(([key, value]) => {
      lines.push(`  --font-${key}: ${value};`);
    });
  }

  // Font sizes
  if (typography.fontSizes) {
    Object.entries(typography.fontSizes).forEach(([key, value]) => {
      lines.push(`  --font-size-${key}: ${value};`);
    });
  }

  // Font weights
  if (typography.fontWeights) {
    Object.entries(typography.fontWeights).forEach(([key, value]) => {
      lines.push(`  --font-weight-${key}: ${value};`);
    });
  }

  // Line heights
  if (typography.lineHeights) {
    Object.entries(typography.lineHeights).forEach(([key, value]) => {
      lines.push(`  --line-height-${key}: ${value};`);
    });
  }

  // Letter spacing
  if (typography.letterSpacing) {
    Object.entries(typography.letterSpacing).forEach(([key, value]) => {
      lines.push(`  --letter-spacing-${key}: ${value};`);
    });
  }

  return lines;
}

/**
 * Convert spacing tokens to CSS custom properties
 */
function generateSpacingVariables(spacing) {
  const lines = [];

  Object.entries(spacing).forEach(([key, value]) => {
    lines.push(`  --spacing-${key}: ${value};`);
  });

  return lines;
}

/**
 * Convert border radius tokens to CSS custom properties
 */
function generateBorderRadiusVariables(borderRadius) {
  const lines = [];

  Object.entries(borderRadius).forEach(([key, value]) => {
    lines.push(`  --radius-${key}: ${value};`);
  });

  return lines;
}

/**
 * Convert shadow tokens to CSS custom properties
 */
function generateShadowVariables(shadows) {
  const lines = [];

  Object.entries(shadows).forEach(([key, value]) => {
    lines.push(`  --shadow-${key}: ${value};`);
  });

  return lines;
}

/**
 * Convert animation tokens to CSS custom properties
 */
function generateAnimationVariables(animations) {
  const lines = [];

  if (animations.durations) {
    Object.entries(animations.durations).forEach(([key, value]) => {
      lines.push(`  --duration-${key}: ${value};`);
    });
  }

  if (animations.easings) {
    Object.entries(animations.easings).forEach(([key, value]) => {
      lines.push(`  --ease-${key}: ${value};`);
    });
  }

  return lines;
}

/**
 * Convert iOS-specific tokens to CSS custom properties
 */
function generateIosVariables(ios) {
  const lines = [];

  if (ios.touchTarget) {
    Object.entries(ios.touchTarget).forEach(([key, value]) => {
      lines.push(`  --touch-target-${key}: ${value};`);
    });
  }

  if (ios.separators) {
    Object.entries(ios.separators).forEach(([key, value]) => {
      lines.push(`  --separator-${key}: ${value};`);
    });
  }

  if (ios.statusBar) {
    Object.entries(ios.statusBar).forEach(([key, value]) => {
      const cssKey = key.replace(/([A-Z])/g, '-$1').toLowerCase();
      lines.push(`  --status-bar-${cssKey}: ${value};`);
    });
  }

  if (ios.navigationBar) {
    Object.entries(ios.navigationBar).forEach(([key, value]) => {
      const cssKey = key.replace(/([A-Z])/g, '-$1').toLowerCase();
      lines.push(`  --nav-bar-${cssKey}: ${value};`);
    });
  }

  if (ios.tabBar) {
    Object.entries(ios.tabBar).forEach(([key, value]) => {
      const cssKey = key.replace(/([A-Z])/g, '-$1').toLowerCase();
      lines.push(`  --tab-bar-${cssKey}: ${value};`);
    });
  }

  if (ios.safeArea) {
    Object.entries(ios.safeArea).forEach(([key, value]) => {
      const cssKey = key.replace(/([A-Z])/g, '-$1').toLowerCase();
      lines.push(`  --safe-area-${cssKey}: ${value};`);
    });
  }

  return lines;
}

/**
 * Convert breakpoints to CSS custom properties
 */
function generateBreakpointVariables(breakpoints) {
  const lines = [];

  Object.entries(breakpoints).forEach(([key, value]) => {
    lines.push(`  --breakpoint-${key}: ${value};`);
  });

  return lines;
}

/**
 * Convert z-index tokens to CSS custom properties
 */
function generateZIndexVariables(zIndex) {
  const lines = [];

  Object.entries(zIndex).forEach(([key, value]) => {
    const cssKey = key.replace(/([A-Z])/g, '-$1').toLowerCase();
    lines.push(`  --z-${cssKey}: ${value};`);
  });

  return lines;
}

/**
 * Main function to generate theme CSS
 */
function generateTheme() {
  console.log('🎨 Generating Tailwind 4.0 theme from design-system.json...\n');

  try {
    // Read design system
    const designSystemJson = readFileSync(DESIGN_SYSTEM_PATH, 'utf-8');
    const designSystem = JSON.parse(designSystemJson);

    console.log(`✓ Loaded design-system.json v${designSystem.version}`);
    console.log(`  Last updated: ${designSystem.last_updated}\n`);

    // Generate CSS variables
    const cssVariables = [];

    console.log('Generating CSS variables for:');

    if (designSystem.colors) {
      console.log('  ✓ Colors (primary, secondary, semantic, background, text, gray)');
      cssVariables.push('  /* Colors */');
      cssVariables.push(...generateColorVariables(designSystem.colors));
      cssVariables.push('');
    }

    if (designSystem.typography) {
      console.log('  ✓ Typography (fonts, sizes, weights, line-heights, letter-spacing)');
      cssVariables.push('  /* Typography */');
      cssVariables.push(...generateTypographyVariables(designSystem.typography));
      cssVariables.push('');
    }

    if (designSystem.spacing) {
      console.log('  ✓ Spacing');
      cssVariables.push('  /* Spacing */');
      cssVariables.push(...generateSpacingVariables(designSystem.spacing));
      cssVariables.push('');
    }

    if (designSystem.borderRadius) {
      console.log('  ✓ Border Radius');
      cssVariables.push('  /* Border Radius */');
      cssVariables.push(...generateBorderRadiusVariables(designSystem.borderRadius));
      cssVariables.push('');
    }

    if (designSystem.shadows) {
      console.log('  ✓ Shadows');
      cssVariables.push('  /* Shadows */');
      cssVariables.push(...generateShadowVariables(designSystem.shadows));
      cssVariables.push('');
    }

    if (designSystem.animations) {
      console.log('  ✓ Animations (durations, easings)');
      cssVariables.push('  /* Animations */');
      cssVariables.push(...generateAnimationVariables(designSystem.animations));
      cssVariables.push('');
    }

    if (designSystem.ios) {
      console.log('  ✓ iOS-specific tokens');
      cssVariables.push('  /* iOS-specific */');
      cssVariables.push(...generateIosVariables(designSystem.ios));
      cssVariables.push('');
    }

    if (designSystem.breakpoints) {
      console.log('  ✓ Breakpoints');
      cssVariables.push('  /* Breakpoints */');
      cssVariables.push(...generateBreakpointVariables(designSystem.breakpoints));
      cssVariables.push('');
    }

    if (designSystem.zIndex) {
      console.log('  ✓ Z-Index');
      cssVariables.push('  /* Z-Index */');
      cssVariables.push(...generateZIndexVariables(designSystem.zIndex));
    }

    // Generate final CSS
    const css = `/**
 * Design Tokens - Generated from design-system.json
 *
 * WARNING: This file is AUTO-GENERATED. Do not edit manually!
 * Run 'npm run generate:theme' to regenerate.
 *
 * Source: design-system.json v${designSystem.version}
 * Generated: ${new Date().toISOString()}
 */

@theme {
${cssVariables.join('\n')}
}
`;

    // Write output
    writeFileSync(OUTPUT_PATH, css, 'utf-8');

    console.log(`\n✅ Theme generated successfully!`);
    console.log(`   Output: ${OUTPUT_PATH}`);
    console.log(`   Variables: ${cssVariables.filter(l => l.trim().startsWith('--')).length}`);
    console.log(`\n💡 Make sure to import this file in your app.css:\n   @import './design-tokens.css';`);

  } catch (error) {
    console.error('❌ Error generating theme:', error.message);
    process.exit(1);
  }
}

// Run
generateTheme();