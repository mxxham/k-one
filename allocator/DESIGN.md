# Allocator Design System

> Aligned with K-one `wms-style.css` design tokens. The allocator is a standalone tool — no auth, no sidebar, focused single-purpose flow.

## 1. Design Tokens (from wms-style.css)

### Colors
| Token | Value | Usage |
|-------|-------|-------|
| `--wms-primary` | `#026766` | CKB Teal — primary actions, headers |
| `--wms-dark` | `#014f4e` | Dark teal — hover states, emphasis |
| `--wms-light` | `#e6f4f4` | Light teal — backgrounds, chips |
| `--wms-lighter` | `#f0f9f9` | Very light — hover rows, subtle bg |
| `--wms-success` | `#2e7d32` | Success states |
| `--wms-warn` | `#b45309` | Warning, replenishments |
| `--wms-info` | `#1565c0` | Info, pickface |
| `--wms-danger` | `#b91c1c` | Errors |
| `--wms-gray-50` | `#f8fafb` | Page background |
| `--wms-gray-100` | `#f1f5f5` | Surface background |
| `--wms-gray-200` | `#e4e9e9` | Borders |
| `--wms-gray-400` | `#9ca3af` | Muted text |
| `--wms-gray-600` | `#4b5563` | Secondary text |
| `--wms-gray-800` | `#1f2937` | Dark text |
| `--wms-text` | `#374151` | Body text |
| `--wms-heading` | `#111827` | Headings |

### Typography
| Element | Font | Size | Weight |
|---------|------|------|--------|
| Page title | Inter | 1.6rem | 800 |
| Card title | Inter | 0.9rem | 700 |
| Body | Inter | 0.85rem | 400-500 |
| Stat number | Inter | 1.8rem | 800 |
| Stat label | Inter | 0.73rem | 500 |
| Chip/badge | Inter | 0.73rem | 700 |
| Monospace | SF Mono, Consolas | 0.85rem | 600 |

### Spacing
| Token | Value |
|-------|-------|
| Card padding | 18px 20px |
| Section gap | 16px |
| Element gap | 8px-12px |
| Border radius (sm) | 8px |
| Border radius (md) | 12px |
| Border radius (lg) | 16px |

### Shadows
| Token | Value |
|-------|-------|
| `--shadow-sm` | `0 1px 4px rgba(0,0,0,.06)` |
| `--shadow-md` | `0 2px 12px rgba(0,0,0,.09)` |
| `--shadow-lg` | `0 8px 30px rgba(0,0,0,.12)` |

## 2. Component Architecture

### Page Layout
```
┌─────────────────────────────────────────────┐
│  Banner (hero) — gradient + stats           │
├─────────────────────────────────────────────┤
│  Container (max-width: 800px, centered)     │
│  ┌─────────────────────────────────────────┐│
│  │ Card: Sheet Info                        ││
│  ├─────────────────────────────────────────┤│
│  │ Card: Upload Zone                       ││
│  │  ├── Drop zone (drag & drop)            ││
│  │  ├── File info (hidden by default)      ││
│  │  └── Generate button                    ││
│  ├─────────────────────────────────────────┤│
│  │ Card: Progress (hidden by default)      ││
│  │  ├── Spinner + title                    ││
│  │  └── Progress bar                       ││
│  ├─────────────────────────────────────────┤│
│  │ Card: Results (hidden by default)       ││
│  │  ├── Stat cards (5 metrics)             ││
│  │  ├── Error cards (if any)               ││
│  │  ├── Picks list                         ││
│  │  ├── Replenishments list                ││
│  │  └── Action buttons                     ││
│  └─────────────────────────────────────────┘│
└─────────────────────────────────────────────┘
```

### Component Classes
| Component | Class | Description |
|-----------|-------|-------------|
| Banner | `.wms-banner` | Hero section with gradient + stats |
| Card | `.wms-card` | White card with border + shadow |
| Card header | `.wms-card-header` | Card title bar |
| Card body | `.wms-card-body` | Card content area |
| Button primary | `.wms-btn .wms-btn-primary` | Teal CTA |
| Button success | `.wms-btn .wms-btn-success` | Green action |
| Button ghost | `.wms-btn .wms-btn-ghost` | Secondary action |
| Table | `.wms-table` | Data table |
| Badge | `.wms-badge` | Status indicator |
| Chip | `.chip-*` | Location/item tags |

### Allocator-Specific Components
| Component | Class | Description |
|-----------|-------|-------------|
| Drop zone | `.alloc-dropzone` | File upload area |
| Drop zone (drag) | `.alloc-dropzone.is-drag` | Active drag state |
| Drop zone (has file) | `.alloc-dropzone.has-file` | File selected state |
| File info | `.alloc-file-info` | Selected file display |
| Progress bar | `.alloc-progress` | Processing indicator |
| Stat card | `.alloc-stat` | Metric display |
| Pick card | `.alloc-pick` | Individual pick row |
| Pick card (full pallet) | `.alloc-pick--full` | Full pallet pick |
| Pick card (pickface) | `.alloc-pick--pickface` | Pickface pick |
| Pick card (replenish) | `.alloc-pick--replenish` | Replenishment task |
| Action row | `.alloc-actions` | Download/Print/Reset buttons |

## 3. Interaction States

### Drop Zone
| State | Visual |
|-------|--------|
| Default | Dashed border, light bg |
| Hover | Border color change, subtle bg shift |
| Drag over | Border color change, bg shift, scale hint |
| Has file | Solid border, success color |

### Buttons
| State | Visual |
|-------|--------|
| Default | Solid bg, shadow |
| Hover | Darker bg, larger shadow |
| Disabled | 50% opacity, no cursor |
| Loading | Spinner icon |

### Pick Cards
| Type | Left border | Icon |
|------|-------------|------|
| Full pallet | Green (#2e7d32) | `fa-box` |
| Pickface | Blue (#1565c0) | `fa-cubes` |
| Replenishment | Amber (#b45309) | `fa-exchange-alt` |

## 4. Responsive Behavior

| Breakpoint | Layout |
|------------|--------|
| < 640px | Single column, full-width cards |
| 640-1024px | Max-width 800px centered |
| > 1024px | Max-width 800px centered |

Stat grid: `grid-template-columns: repeat(auto-fit, minmax(130px, 1fr))`

## 5. Accessibility

- All interactive elements have visible focus states
- Color contrast meets WCAG AA (4.5:1 for text)
- Drop zone is keyboard accessible (Enter/Space to open file dialog)
- Progress bar has `role="progressbar"` and `aria-valuenow`
- Error messages use `role="alert"`

## 6. Accepted Debt

- Font Awesome icons (not Lucide) — already used throughout K-one
- No animation library — CSS transitions only
- Inline `<script>` in index.php — acceptable for standalone tool
