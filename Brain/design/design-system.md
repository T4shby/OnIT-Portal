# On IT Design System Reference
 
## Colour Tokens
 
```css
/* Core */
--orange:  #ff7000;   /* CTAs, icons, rules, hover states, priority badges */
--dark:    #011926;   /* All major section backgrounds */
--surface: #0a2536;   /* Slightly lighter surface (rare) */
 
/* Card surfaces */
--card-bg:     #071f2e;   /* Solid card background — never transparent */
--card-border: #0f3048;   /* Solid card border — never rgba */
 
/* Section dividers */
--border: #1F2933;
 
/* Text */
--text-primary: rgba(255,255,255,0.82);   /* Main body copy */
--text-muted:   rgba(255,255,255,0.62);   /* Supporting/secondary text */
--text-dim:     rgba(255,255,255,0.4);    /* Labels, meta info */
```
 
**Colour rules:**
- Orange = action and emphasis only. Never paragraph text.
- Navy `#011926` = default section background. Not `#000` or `#111`.
- Cards are always **solid** — `#071f2e` background, `#0f3048` border. The grid must not bleed through.
- Body text is always white-based (`rgba(255,255,255,...)`). Never `#aaa`, `#999`, or Tailwind grey classes without overriding.
- No new colours may be introduced to the palette.
---
 
## Typography
 
### Font loading
```html
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800;900&family=Barlow:wght@300;400;500;600&display=swap" rel="stylesheet" />
```
 
### Usage
 
| Element | Font | Weight | Transform | Size |
|---------|------|--------|-----------|------|
| Hero heading | Barlow Condensed | 900 | Uppercase | `4rem` desktop / `2.4rem` mobile — use `hero-heading` class |
| Section heading | Barlow Condensed | 800 | Uppercase | `2.1rem` |
| Agenda number | Barlow Condensed | 800 | — | `2rem`, colour `#ff7000` |
| Card title | Barlow Condensed | 700 | Uppercase | `1rem`, `letter-spacing: 0.04em` |
| Agenda title | Barlow Condensed | 600 | Uppercase | `1.05rem`, `letter-spacing: 0.03em` |
| FAQ question | Barlow Condensed | 700 | Uppercase | `1rem`, `letter-spacing: 0.04em` |
| CTA button | Barlow Condensed | 700 | Uppercase | `letter-spacing: 0.04em` |
| Badge / label | Barlow Condensed | 700 | Uppercase | `0.75rem`, `letter-spacing: 0.1em` |
| Body / paragraph | Barlow | 300 | None | `0.875rem`–`1.05rem`, `rgba(255,255,255,0.82)` |
| Muted body | Barlow | 300 | None | `0.875rem`, `rgba(255,255,255,0.62)` |
| SLA table header | Barlow Condensed | 700 | Uppercase | `0.75rem`, colour `#ff7000` |
| SLA table body | Barlow | 300 | None | `0.875rem` |
 
---
 
## Heading Stack Pattern
 
The core On IT heading pattern. Lines stack flush, always left-aligned, never centred.
 
```html
<!-- Hero (large) -->
<div class="heading-stack">
  <h1 class="hero-heading white-bg wipe-heading">Line One</h1>
  <h1 class="hero-heading orange-bg wipe-heading wipe-delay">Line Two</h1>
</div>
 
<!-- Section (smaller) -->
<div class="heading-stack">
  <h2 class="section-heading white-bg wipe-heading">Line One</h2>
  <h2 class="section-heading orange-bg wipe-heading wipe-delay">Line Two</h2>
</div>
```
 
```css
.heading-stack {
  display: inline-flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0;
  line-height: 1;
}
.hero-heading {
  display: inline-block;
  padding: 10px 18px;
  font-family: "Barlow Condensed", sans-serif;
  font-size: 4rem;
  font-weight: 900;
  line-height: 1;
  letter-spacing: -0.01em;
  text-transform: uppercase;
  margin: 0;
}
.hero-heading.white-bg  { background: #ffffff; color: #011926; }
.hero-heading.orange-bg { background: #ff7000; color: #011926; }
 
.section-heading {
  display: inline-block;
  padding: 7px 14px;
  font-family: "Barlow Condensed", sans-serif;
  font-size: 2.1rem;
  font-weight: 800;
  line-height: 1;
  letter-spacing: -0.01em;
  text-transform: uppercase;
  margin: 0;
  clip-path: inset(0 100% 0 0); /* reset state for wipe animation */
}
.section-heading.white-bg  { background: #ffffff; color: #011926; }
.section-heading.orange-bg { background: #ff7000; color: #011926; }
```
 
**Rules:**
- White-bg line always comes first. Orange-bg line always last.
- Can be 2 or 3 lines — split thoughtfully at natural phrase breaks.
- Never centre the heading stack. Always left-aligned.
- Always precede with `.orange-rule`.
---
 
## Orange Rule
 
Placed above every section heading, no exceptions:
 
```html
<div class="orange-rule"></div>
```
```css
.orange-rule { width: 40px; height: 3px; background: #ff7000; margin-bottom: 1.5rem; }
```
 
---
 
## Spacing System
 
- Section vertical padding: `py-20` (80px)
- Max content width: `max-w-5xl mx-auto` for most sections; `max-w-4xl` for narrow content (FAQs)
- Section heading block bottom margin: `mb-14`
- Card inner padding: `p-7`
- Horizontal page padding: `px-6`
---
 
## Section Backgrounds
 
Sections alternate between two treatments:
 
| Treatment | CSS |
|-----------|-----|
| Standard dark | `background: #011926` (from body/onit-bg) |
| Slightly darkened | `style="background:rgba(0,0,0,0.15);"` |
| Background image | See image section pattern in SKILL.md |
 
The alternation creates rhythm. Image sections break the pattern where visual interest is needed.
 
---
 
## Priority / Status Badges (SLA tables)
 
```css
.priority-badge {
  display: inline-block;
  font-family: "Barlow Condensed", sans-serif;
  font-weight: 700;
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 2px 8px;
}
.priority-critical { background: rgba(255,60,60,0.15);  border: 1px solid rgba(255,60,60,0.3);  color: #ff6060; }
.priority-high     { background: rgba(255,112,0,0.15);  border: 1px solid rgba(255,112,0,0.3);  color: #ff7000; }
.priority-medium   { background: rgba(255,185,0,0.12);  border: 1px solid rgba(255,185,0,0.25); color: #ffc230; }
.priority-low      { background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: #aaa; }
.priority-verylow  { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.06); color: #777; }
```
 
---
 
## Animation Classes (full system)
 
See SKILL.md for the complete CSS and JS. Summary of classes to apply:
 
| Class | Apply to |
|-------|----------|
| `animate-in delay-1` | Hero orange rule |
| `animate-in delay-2` | Hero heading stack (the wrapper div) |
| `animate-in delay-3` | Hero subtext paragraph(s) |
| `animate-in delay-4` | Hero CTA button row |
| `reveal` | Agenda items, FAQ items, SLA table, intro paragraphs |
| `card-reveal` | Every `.benefit-card` (JS adds stagger delay automatically) |
| `wipe-heading` | Every `.white-bg` heading — both `.hero-heading` and `.section-heading` |
| `wipe-heading wipe-delay` | Every `.orange-bg` heading — both `.hero-heading` and `.section-heading` |
 
All animations **replay on scroll up and down** — observers remove classes when elements leave viewport.