---
name: onit-website
description: >
  Build, edit, or create any web page or landing page for On IT (onit.ltd) — an MSP/IT services brand.
  Use this skill whenever the user mentions building a website, landing page, campaign page, webinar page,
  service page, or any HTML/Next.js file for On IT. Also trigger when the user says "make it On IT branded",
  "use our design system", "match the website style", or pastes/references existing On IT pages.
  This skill enforces brand consistency: correct colours, typography, components, deployment targets,
  and code patterns. Do NOT skip this skill for "quick" pages — brand consistency matters on every output.
---

## Portal application (`app.onit.ltd`)

This skill applies to the **On IT Portal** Laravel app as well as marketing pages.

| Topic | Portal implementation |
|---|---|
| CSS classes | `resources/css/app.css` |
| Tailwind tokens | `tailwind.config.js` (`onit-*` colours, Barlow fonts) |
| Client layout | `resources/views/components/layouts/app.blade.php` |
| Admin layout | `resources/views/components/layouts/admin.blade.php` |
| Portal-specific rules | [Brain/UIUX.md](../UIUX.md) |

**Portal differences:** includes nav header and admin sidebar; no hero background images or scroll-wipe animations on dashboard; no GHL form embeds. All other brand rules (colours, fonts, heading stack, benefit cards, square CTAs) apply.

---
 
# On IT Website Skill
 
On IT is a dark-first, security-led MSP brand targeting SME decision-makers.
The visual tone is **bold, technical, and assured** — never playful or decorative.
 
Before writing any code, read this file fully. Then load `design-system.md` and `components.md` in this folder.
 
---
 
## Reference Files
 
| File | When to load |
|------|-------------|
| `design-system.md` | Always — colours, fonts, spacing, backgrounds, animations |
| `components.md` | Always — cards, CTAs, headings, agenda items, tables, FAQs, image sections |
 
---
 
## Stack
 
On IT pages are built as **standalone HTML** unless the user explicitly requests Next.js:
 
- Single `.html` file, no build step
- Tailwind CSS via CDN: `https://cdn.tailwindcss.com/3.4.17`
- Lucide icons via CDN: `https://cdn.jsdelivr.net/npm/lucide@0.263.0/dist/umd/lucide.min.js`
- Google Fonts: `Barlow Condensed` + `Barlow` loaded via `<link>` tag
- Pages are embedded into GoHighLevel (GHL) — **no nav, no footer** unless explicitly requested
- Always call `lucide.createIcons()` in the script block
---
 
## Non-Negotiable Brand Rules
 
These apply to every page, no exceptions:
 
1. **Background**: `#011926` for all major sections. Never black or generic dark greys.
2. **Accent**: `#FF7000` for CTAs, icons, rules, hover states, priority badges. Never for paragraph text.
3. **Fonts**: `Barlow Condensed` (900/800/700, uppercase) for headings. `Barlow` (300–500) for body.
4. **Heading stack**: Always `.heading-stack` — white-bg block first, orange-bg block last. Always left-aligned. Never centred except when explicitly asked.
5. **Orange rule**: Every section heading is preceded by a `40px × 3px` orange rule div. No exceptions.
6. **CTA buttons**: Square corners only (`border-radius: 0`). Primary = orange fill. Secondary = ghost with orange border. Both uppercase Barlow Condensed.
7. **Cards**: Solid opaque background `#071f2e`, border `#0f3048`. Never transparent/glassy — the grid must not bleed through cards.
8. **No decorative flourishes**: No rounded cards, no pastel accents, no gradients on text, no emoji, no shadows on cards.
9. **No stats sections** unless the numbers are pulled live from an API. Do not invent static stats.
10. **No nav or footer** — pages are embedded in GHL. Only add these if the user explicitly requests them.
11. **Paragraph text**: Use `rgba(255,255,255,0.82)` for main body copy. Use `rgba(255,255,255,0.62)` for supporting/muted text. Never `#aaa` or Tailwind's `text-gray-500` without overriding to white-based values.
12. **No em dashes** (`—`) in copy. Use a hyphen (`-`) or repunctuate as a new sentence.
13. **Image CDN**: On IT images live at `assets.cdn.filesafe.space/p7B8DyydXAOYUpefU8Ql/media/...`. Never invent image URLs — ask the user.
---
 
## Page Structure (standard service/landing page)
 
Every On IT page follows this pattern unless the brief says otherwise:
 
```
1. Hero            — full-bleed bg image, gradient overlay, heading-stack, subtext, dual CTAs
2. How It Works    — numbered agenda items (01/02/03), left-border hover
3. What We Cover   — 3-column benefit cards, icon boxes
4. Details/Table   — SLA table, pricing, specs, or comparison (opaque surface)
5. Differentiators — 3-column benefit cards (second set, different angle)
6. Reporting/Trust — optional 3-column cards with evidence or social proof
7. FAQs            — accordion, left orange border
8. CTA             — left-aligned, heading stack, dual buttons
```
 
Sections alternate between standard dark background (`#011926`) and slightly darkened (`rgba(0,0,0,0.15)` overlay). Image sections break this rhythm where relevant.
 
---
 
## Section Alternation Pattern
 
```
Hero          → bg image with overlay (0.88 / 0.65 / 0.3)
How It Works  → rgba(0,0,0,0.15) overlay
What We Cover → bg image with overlay (if image provided), else plain dark
SLA/Table     → rgba(0,0,0,0.15) overlay
Differentiators → plain dark
Reporting     → bg image with overlay (if image provided), else rgba(0,0,0,0.15)
FAQs          → plain dark
CTA           → bg image with overlay (0.92 / 0.78 / 0.5) — always a bg image section, never plain dark
```
 
---
 
## Background Image Sections
 
When a background image is provided for a section:
 
```html
<section class="w-full" style="position:relative;overflow:hidden;">
  <div style="position:absolute;inset:0;
    background-image:url('IMAGE_URL');
    background-size:cover;
    background-position:center;
    z-index:0;">
  </div>
  <div style="position:absolute;inset:0;
    background:linear-gradient(to right,
      rgba(1,25,38,0.88) 0%,
      rgba(1,25,38,0.65) 60%,
      rgba(1,25,38,0.3) 100%);
    z-index:1;">
  </div>
  <div class="px-6 py-20" style="position:relative;z-index:2;">
    <div class="max-w-5xl mx-auto">
      <!-- content -->
    </div>
  </div>
</section>
```
 
**Overlay values by context** — use these exact values, do not mix them:
- **Hero section**: `0.88 / 0.65 / 0.3` — image is decorative, slightly darker on the left where text sits
- **CTA section**: `0.92 / 0.78 / 0.5` — much darker because a form and bullets must be readable over the image
 
**No parallax** — the user has determined parallax is not wanted. Images are static.
 
---
 
## Animation System
 
All pages use this full animation system. Include every block.
 
### Hero entrance (fires on load)
```css
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(24px); }
  to   { opacity: 1; transform: translateY(0); }
}
.animate-in { animation: fadeUp 0.65s ease forwards; opacity: 0; }
.delay-1 { animation-delay: 0.08s; }
.delay-2 { animation-delay: 0.18s; }
.delay-3 { animation-delay: 0.28s; }
.delay-4 { animation-delay: 0.4s; }
```
 
Apply to hero elements in this exact order:
- `delay-1` → `.orange-rule`
- `delay-2` → `.heading-stack` (the whole stack, not individual lines)
- `delay-3` → hero subtext paragraph(s)
- `delay-4` → CTA button row
 
### Scroll reveal (fires as elements enter viewport)
```css
.reveal { opacity: 0; transform: translateY(28px); transition: opacity 0.6s ease, transform 0.6s ease; }
.reveal.visible { opacity: 1; transform: translateY(0); }
.reveal-d1 { transition-delay: 0.07s; }
.reveal-d2 { transition-delay: 0.17s; }
.reveal-d3 { transition-delay: 0.27s; }
.reveal-d4 { transition-delay: 0.37s; }
```
 
Apply `.reveal` to: agenda items, FAQ items, SLA table wrapper, section intro paragraphs.
 
### Section heading wipe (left-to-right clip-path reveal)
```css
@keyframes wipeIn {
  from { clip-path: inset(0 100% 0 0); }
  to   { clip-path: inset(0 0% 0 0); }
}
.wipe-heading { clip-path: inset(0 100% 0 0); }
.wipe-heading.wipe-visible { animation: wipeIn 0.52s cubic-bezier(0.4,0,0.2,1) forwards; }
.wipe-heading.wipe-visible.wipe-delay { animation-delay: 0.2s; }
```
 
- Add `wipe-heading` to **every** `.white-bg` heading — both `.hero-heading.white-bg` and `.section-heading.white-bg`
- Add `wipe-heading wipe-delay` to **every** `.orange-bg` heading — both `.hero-heading.orange-bg` and `.section-heading.orange-bg`
- White line wipes first, orange follows 200ms later
### Card stagger
```css
.card-reveal { opacity: 0; transform: translateY(32px); transition: opacity 0.55s ease, transform 0.55s ease; }
.card-reveal.visible { opacity: 1; transform: translateY(0); }
```
 
Add `.card-reveal` to every `.benefit-card`. JS applies stagger delay automatically.
 
### JavaScript (always include this full block)
```javascript
lucide.createIcons();
 
// FAQ accordion
document.querySelectorAll('.faq-item').forEach(item => {
  item.addEventListener('click', () => {
    const isOpen = item.classList.contains('open');
    document.querySelectorAll('.faq-item').forEach(i => i.classList.remove('open'));
    if (!isOpen) item.classList.add('open');
    lucide.createIcons();
  });
});
 
// Scroll reveal — replays on scroll up and down
const revealObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.classList.add('visible');
    } else {
      entry.target.classList.remove('visible');
    }
  });
}, { rootMargin: '0px 0px -12% 0px', threshold: 0 });
 
document.querySelectorAll('.reveal').forEach(el => revealObserver.observe(el));
 
// Card stagger — sequential delay per position in grid
document.querySelectorAll('.card-reveal').forEach(card => {
  const siblings = Array.from(card.parentElement.querySelectorAll('.card-reveal'));
  card.style.transitionDelay = (siblings.indexOf(card) * 0.12) + 's';
  revealObserver.observe(card);
});
 
// Heading wipe — replays on scroll up and down
const wipeObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    const stack = entry.target.closest('.mb-14') || entry.target.closest('.mb-8') || entry.target.parentElement;
    if (entry.isIntersecting) {
      stack.querySelectorAll('.wipe-heading').forEach(h => h.classList.add('wipe-visible'));
    } else {
      stack.querySelectorAll('.wipe-heading').forEach(h => h.classList.remove('wipe-visible'));
    }
  });
}, { rootMargin: '0px 0px -15% 0px', threshold: 0 });
 
document.querySelectorAll('.wipe-heading:not(.wipe-delay)').forEach(h => wipeObserver.observe(h));
```
 
---
 
## CSS Variables
 
Always define in `:root`:
 
```css
:root {
  --orange: #ff7000;
  --dark: #011926;
  --surface: #0a2536;
}
```
 
---
 
## Global Background
 
Apply `.onit-bg` to `<body>` and place `.grid-overlay` as the first child:
 
```css
.onit-bg {
  background:
    radial-gradient(ellipse 80% 60% at 20% 10%, rgba(255,112,0,0.07) 0%, transparent 60%),
    radial-gradient(ellipse 60% 50% at 80% 80%, rgba(255,112,0,0.04) 0%, transparent 60%),
    #011926;
}
.grid-overlay {
  background-image:
    linear-gradient(rgba(255,255,255,0.015) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,0.015) 1px, transparent 1px);
  background-size: 60px 60px;
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 0;
}
```
 
All `<section>` elements need `position: relative; z-index: 1;` to sit above the grid overlay.
 
---
 
## Forms
 
- GHL forms are embedded via `<iframe>` pointing to `links.growably.com`
- Always include `<script src="https://links.growably.com/js/form_embed.js">` after the iframe
- Always ask the user for the GHL Form ID — never invent one
---
 
## What NOT to Do
 
- ❌ No `rounded-lg` or `rounded-xl` on any card or button — sharp edges only
- ❌ No colours outside the palette (no blues, teals, purples, greens)
- ❌ No transparent/glassy cards — use solid `#071f2e` so the grid doesn't bleed through
- ❌ No parallax effects
- ❌ No static stats sections — only use live/API-driven numbers
- ❌ No nav or footer (pages are embedded in GHL)
- ❌ No em dashes (`—`) — use hyphens or repunctuate
- ❌ No centred section headings — always left-aligned
- ❌ No `text-gray-500` for body copy without overriding to white-based `rgba(255,255,255,...)` values
- ❌ No invented image URLs — ask the user or use their CDN path
- ❌ No box shadows on cards
- ❌ No `<form>` without confirming the GHL form ID
