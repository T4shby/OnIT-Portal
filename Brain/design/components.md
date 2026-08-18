# On IT Component Patterns
 
## Hero Section (page opener)
 
Always a background image section. `min-height:88vh`. Orange rule → heading → subtext → CTAs.
Hero headings use `hero-heading` (not `section-heading`). Wipe animation applies to hero headings too.
 
```html
<section class="w-full" style="position:relative;overflow:hidden;min-height:88vh;display:flex;align-items:center;">
  <div style="position:absolute;inset:0;
    background-image:url('IMAGE_URL_FROM_CDN');
    background-size:cover;background-position:center;z-index:0;"></div>
  <div style="position:absolute;inset:0;
    background:linear-gradient(to right,rgba(1,25,38,0.88) 0%,rgba(1,25,38,0.65) 60%,rgba(1,25,38,0.3) 100%);
    z-index:1;"></div>
  <div class="px-6 py-20 w-full" style="position:relative;z-index:2;">
    <div class="max-w-5xl mx-auto">
      <div class="orange-rule animate-in delay-1"></div>
      <div class="heading-stack animate-in delay-2" style="margin-bottom:2rem;">
        <h1 class="hero-heading white-bg wipe-heading">Hero Heading</h1>
        <h1 class="hero-heading orange-bg wipe-heading wipe-delay">Line Two</h1>
      </div>
      <p class="animate-in delay-3" style="max-width:520px;font-size:1.05rem;font-weight:300;color:rgba(255,255,255,0.82);line-height:1.75;margin-bottom:0.75rem;">
        Primary hero subtext - one or two sentences.
      </p>
      <p class="animate-in delay-3" style="max-width:520px;font-size:0.875rem;font-weight:300;color:rgba(255,255,255,0.62);line-height:1.7;margin-bottom:2rem;">
        Best for: [target audience description].
      </p>
      <div class="flex flex-wrap gap-4 animate-in delay-4">
        <a href="#contact" class="cta-btn text-white text-base px-10 py-4">Primary CTA</a>
        <a href="#contact" class="cta-btn-ghost text-base px-10 py-4">Secondary CTA</a>
      </div>
    </div>
  </div>
</section>
```
 
**Notes:**
- `min-height:88vh` - always, so the hero fills the viewport
- Orange rule gets `animate-in delay-1` (it animates in before the heading)
- The whole `.heading-stack` div gets `animate-in delay-2`
- Subtext paragraphs share `delay-3`; CTA row gets `delay-4`
- Both `hero-heading` lines get `wipe-heading` / `wipe-heading wipe-delay` (same as section headings)
- Omit the "Best for" paragraph if not relevant to the page
 
---
 
## Section Template (copy-paste starting point)
 
```html
<section class="w-full px-6 py-20" style="background:rgba(0,0,0,0.15);position:relative;z-index:1;">
  <div class="max-w-5xl mx-auto">
    <div class="mb-14">
      <div class="orange-rule"></div>
      <div class="heading-stack">
        <h2 class="section-heading white-bg wipe-heading">First Line</h2>
        <h2 class="section-heading orange-bg wipe-heading wipe-delay">Second Line</h2>
      </div>
      <!-- Optional intro paragraph -->
      <p class="reveal" style="max-width:560px;font-size:0.875rem;font-weight:300;color:rgba(255,255,255,0.62);line-height:1.75;margin-top:1.5rem;">
        Supporting description for this section.
      </p>
    </div>
    <!-- section content -->
  </div>
</section>
```
 
---
 
## Benefit Card (3-column grid)
 
Cards must have a **solid** background - never transparent. The grid overlay must not bleed through.
 
```html
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
  <div class="benefit-card p-7 card-reveal">
    <div class="icon-box mb-6">
      <i data-lucide="zap" style="width:20px;height:20px;color:#ff7000"></i>
    </div>
    <h3 class="card-title">Card Title</h3>
    <p style="font-size:0.875rem;font-weight:300;color:rgba(255,255,255,0.62);line-height:1.6;">
      Body text describing the benefit.
    </p>
  </div>
  <!-- repeat × 3 -->
</div>
```
 
```css
.benefit-card {
  background: #071f2e;
  border: 1px solid #0f3048;
  transition: all 0.28s ease;
  position: relative;
  overflow: hidden;
}
.benefit-card::before {
  content: "";
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: #ff7000;
  transform: scaleX(0);
  transform-origin: left;
  transition: transform 0.3s ease;
}
.benefit-card:hover { background: #0a2a3f; transform: translateY(-4px); }
.benefit-card:hover::before { transform: scaleX(1); }
 
.icon-box {
  width: 40px; height: 40px;
  display: flex; align-items: center; justify-content: center;
  background: rgba(255,112,0,0.1);
  border: 1px solid rgba(255,112,0,0.2);
}
.card-title {
  font-family: "Barlow Condensed", sans-serif;
  text-transform: uppercase;
  font-size: 1rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: #ffffff;
  margin-bottom: 0.75rem;
}
```
 
---
 
## Bullet List inside a Card
 
Used for "what we help with" type content inside benefit cards:
 
```html
<ul class="support-list">
  <li>Item one</li>
  <li>Item two</li>
</ul>
```
 
```css
.support-list { list-style: none; margin: 0; padding: 0; }
.support-list li {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  padding: 0.45rem 0;
  font-size: 0.875rem;
  font-weight: 300;
  color: rgba(255,255,255,0.72);
  border-bottom: 1px solid rgba(255,255,255,0.04);
}
.support-list li:last-child { border-bottom: none; }
.support-list li::before {
  content: "";
  display: inline-block;
  width: 6px; height: 6px;
  background: #ff7000;
  margin-top: 0.45rem;
  flex-shrink: 0;
}
```
 
---
 
## Client setup manual (onboarding checklist)
 
Used inside `.onboarding-guide__panel` on **Admin → Clients → Edit**. Structured like an install manual - not a flat bullet list.
 
| Block | CSS | Purpose |
|-------|-----|---------|
| Important | `.onboarding-manual__callout` | One-app-only, consent vs login, etc. |
| Before you start | `.onboarding-manual__prerequisites` | Prerequisites (not numbered) |
| Part A / B / C | `.onboarding-manual__section` + `.onboarding-manual__steps` | Numbered steps per section (counter resets each part) |
| Check your work | `.onboarding-manual__verify` | Verification checklist |
 
Rendered by `resources/views/admin/clients/_onboarding-manual.blade.php`. Data from `App\Services\OnboardingManual`.
 
---
 
## Agenda / How It Works List
 
Numbered steps with orange left-border on hover. Used for process flows and step-by-step sections.
 
```html
<div class="space-y-0">
  <div class="agenda-item pl-6 py-6 border-b reveal" style="border-bottom-color:rgba(255,255,255,0.05)">
    <div class="flex items-start gap-5">
      <span style="font-family:'Barlow Condensed',sans-serif;font-size:2rem;font-weight:800;color:#ff7000;line-height:1;flex-shrink:0;">01</span>
      <div>
        <h3 class="agenda-title mb-1">Step Title</h3>
        <p style="font-size:0.875rem;font-weight:300;color:rgba(255,255,255,0.62);line-height:1.7;">
          Description of this step.
        </p>
      </div>
    </div>
  </div>
  <!-- repeat - last item omits border-b -->
</div>
```
 
```css
.agenda-item {
  border-left: 3px solid rgba(255,255,255,0.08);
  transition: border-color 0.3s ease;
}
.agenda-item:hover { border-left-color: #ff7000; }
.agenda-title {
  font-family: "Barlow Condensed", sans-serif;
  text-transform: uppercase;
  font-size: 1.05rem;
  letter-spacing: 0.03em;
  font-weight: 600;
  color: #ffffff;
}
```
 
---
 
## SLA / Data Table
 
Used for service level agreements, pricing tiers, feature comparisons.
Table background must be **solid** - no transparency.
 
```html
<div class="reveal" style="background:#071f2e;border:1px solid #0f3048;overflow-x:auto;">
  <table class="sla-table">
    <thead>
      <tr>
        <th>Impact</th>
        <th>Priority</th>
        <th>Response</th>
        <th>Resolution</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>Description of impact</td>
        <td><span class="priority-badge priority-critical">Critical</span></td>
        <td style="color:#fff;font-weight:500;">3 hours</td>
        <td style="color:#ff7000;font-weight:500;">12 hours</td>
      </tr>
    </tbody>
  </table>
</div>
```
 
```css
.sla-table { width: 100%; border-collapse: collapse; }
.sla-table th {
  font-family: "Barlow Condensed", sans-serif;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  font-size: 0.75rem;
  color: #ff7000;
  padding: 0.9rem 1.1rem;
  text-align: left;
  border-bottom: 1px solid rgba(255,112,0,0.25);
}
.sla-table td {
  padding: 0.9rem 1.1rem;
  font-size: 0.875rem;
  font-weight: 300;
  color: rgba(255,255,255,0.62);
  border-bottom: 1px solid rgba(255,255,255,0.04);
  vertical-align: middle;
}
.sla-table tr:hover td { background: rgba(255,255,255,0.02); }
.sla-table td:first-child { color: #fff; font-weight: 400; }
```
 
---
 
## FAQ Accordion
 
Left orange border activates on open. All items collapse when another opens.
 
```html
<div class="space-y-0" id="faq-list">
  <div class="faq-item pl-6 py-5 border-b reveal" style="border-bottom-color:rgba(255,255,255,0.05)">
    <div class="faq-question">
      <span>Question text here?</span>
      <i data-lucide="chevron-down" class="faq-chevron" style="width:18px;height:18px;"></i>
    </div>
    <div class="faq-answer">Answer text here.</div>
  </div>
  <!-- last item omits border-b -->
</div>
```
 
```css
.faq-item {
  border-left: 3px solid rgba(255,255,255,0.08);
  transition: border-color 0.3s ease;
  cursor: pointer;
}
.faq-item:hover, .faq-item.open { border-left-color: #ff7000; }
.faq-question {
  font-family: "Barlow Condensed", sans-serif;
  text-transform: uppercase;
  font-weight: 700;
  font-size: 1rem;
  letter-spacing: 0.04em;
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
}
.faq-answer {
  display: none;
  font-size: 0.875rem;
  font-weight: 300;
  color: rgba(255,255,255,0.62);
  line-height: 1.7;
  padding-top: 0.75rem;
}
.faq-item.open .faq-answer { display: block; }
.faq-chevron { transition: transform 0.25s ease; color: #ff7000; flex-shrink: 0; }
.faq-item.open .faq-chevron { transform: rotate(180deg); }
```
 
---
 
## CTA Buttons
 
Always square corners. Primary = orange fill. Secondary = ghost with orange border.
Always used as a pair side-by-side.
 
```html
<div class="flex flex-wrap gap-4">
  <a href="#contact" class="cta-btn text-white text-base px-10 py-4">Primary Action</a>
  <a href="#contact" class="cta-btn-ghost text-base px-10 py-4">Secondary Action</a>
</div>
```
 
```css
.cta-btn {
  background: #ff7000;
  border-radius: 0;
  font-family: "Barlow Condensed", sans-serif;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  transition: all 0.22s ease;
  color: #fff;
  text-decoration: none;
  display: inline-block;
}
.cta-btn:hover {
  background: #e56300;
  box-shadow: 0 0 28px 4px rgba(255,112,0,0.28);
  transform: translateY(-2px);
}
.cta-btn-ghost {
  background: transparent;
  border: 1px solid rgba(255,112,0,0.4);
  border-radius: 0;
  font-family: "Barlow Condensed", sans-serif;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  transition: all 0.22s ease;
  color: #ff7000;
  text-decoration: none;
  display: inline-block;
}
.cta-btn-ghost:hover {
  background: rgba(255,112,0,0.08);
  border-color: #ff7000;
  transform: translateY(-2px);
}
```
 
---
 
## CTA Section (page closer)
 
Always a background image section with the darker overlay (`0.92 / 0.78 / 0.5`). Never plain dark.
2-column layout: heading + bullets + dual CTAs on the left, GHL form card on the right.
Always left-aligned. Never centred.
 
```html
<section id="contact" class="w-full" style="position:relative;overflow:hidden;z-index:1;">
  <div style="position:absolute;inset:0;
    background-image:url('IMAGE_URL_FROM_CDN');
    background-size:cover;background-position:center;z-index:0;"></div>
  <div style="position:absolute;inset:0;
    background:linear-gradient(to right,rgba(1,25,38,0.92) 0%,rgba(1,25,38,0.78) 55%,rgba(1,25,38,0.5) 100%);
    z-index:1;"></div>
  <div class="px-6 py-20" style="position:relative;z-index:2;">
    <div class="max-w-5xl mx-auto">
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
        <!-- Left: heading + copy + bullets + CTAs -->
        <div>
          <div class="mb-8">
            <div class="orange-rule"></div>
            <div class="heading-stack">
              <h2 class="section-heading white-bg wipe-heading">Ready for Smoother</h2>
              <h2 class="section-heading orange-bg wipe-heading wipe-delay">IT Support?</h2>
            </div>
          </div>
          <p style="max-width:460px;font-size:0.875rem;font-weight:300;color:rgba(255,255,255,0.62);line-height:1.75;margin-bottom:1.75rem;">
            Supporting copy here.
          </p>
          <ul class="cta-bullet-list">
            <li>Key benefit or reassurance</li>
            <li>Key benefit or reassurance</li>
            <li>Key benefit or reassurance</li>
          </ul>
          <div class="flex flex-wrap gap-4">
            <a href="mailto:hello@onit.ltd" class="cta-btn text-white text-base px-10 py-4">Primary CTA</a>
            <a href="mailto:hello@onit.ltd" class="cta-btn-ghost text-base px-10 py-4">Secondary CTA</a>
          </div>
        </div>
        <!-- Right: GHL form card -->
        <div class="form-card" style="padding:1.25rem;">
          <iframe
            src="https://links.growably.com/widget/form/FORM_ID_HERE"
            style="width:100%;height:480px;border:none;display:block;"
            id="inline-FORM_ID_HERE"
            data-layout="{'id':'INLINE'}"
            data-trigger-type="alwaysShow"
            data-trigger-value=""
            data-activation-type="alwaysActivated"
            data-activation-value=""
            data-deactivation-type="neverDeactivate"
            data-deactivation-value=""
            data-form-name="On IT - Contact Form"
            data-height="480"
            data-layout-iframe-id="inline-FORM_ID_HERE"
            data-form-id="FORM_ID_HERE"
            title="On IT - Contact Form">
          </iframe>
          <script src="https://links.growably.com/js/form_embed.js"></script>
        </div>
      </div>
    </div>
  </div>
</section>
```
 
```css
/* CTA bullet list */
.cta-bullet-list { list-style: none; margin: 0 0 2.5rem; padding: 0; }
.cta-bullet-list li {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  padding: 0.5rem 0;
  font-size: 0.875rem;
  font-weight: 300;
  color: rgba(255,255,255,0.72);
}
.cta-bullet-list li::before {
  content: "";
  display: inline-block;
  width: 6px; height: 6px;
  background: #ff7000;
  margin-top: 0.45rem;
  flex-shrink: 0;
}
 
/* GHL form card */
.form-card {
  background: #071f2e;
  border: 1px solid #0f3048;
  padding: 2rem;
}
```
 
---
 
## Background Image Section
 
For sections with a full-bleed background photo. Use the correct overlay for the context - do not mix them.
 
**Hero sections** (`0.88 / 0.65 / 0.3`):
```html
<div style="position:absolute;inset:0;
  background:linear-gradient(to right,
    rgba(1,25,38,0.88) 0%,
    rgba(1,25,38,0.65) 60%,
    rgba(1,25,38,0.3) 100%);
  z-index:1;">
</div>
```
 
**CTA sections** (`0.92 / 0.78 / 0.5`) - darker because form and bullets must be readable:
```html
<div style="position:absolute;inset:0;
  background:linear-gradient(to right,
    rgba(1,25,38,0.92) 0%,
    rgba(1,25,38,0.78) 55%,
    rgba(1,25,38,0.5) 100%);
  z-index:1;">
</div>
```
 
Full section wrapper (same for both - swap overlay above):
```html
<section class="w-full" style="position:relative;overflow:hidden;">
  <div style="position:absolute;inset:0;
    background-image:url('IMAGE_URL_FROM_CDN');
    background-size:cover;
    background-position:center;
    z-index:0;">
  </div>
  <!-- overlay div here (see above) -->
  <div class="px-6 py-20" style="position:relative;z-index:2;">
    <div class="max-w-5xl mx-auto">
      <!-- section content -->
    </div>
  </div>
</section>
```
 
**No parallax.** Background is static.
**Image CDN path**: `assets.cdn.filesafe.space/p7B8DyydXAOYUpefU8Ql/media/FILENAME`
 
---
 
## GHL Embedded Form
 
```html
<iframe
  src="https://links.growably.com/widget/form/FORM_ID_HERE"
  style="width:100%;height:762px;border:none;"
  id="inline-FORM_ID_HERE"
  data-layout="{'id':'INLINE'}"
  data-trigger-type="alwaysShow"
  data-form-name="FORM_NAME"
  data-height="762"
  data-form-id="FORM_ID_HERE"
  title="FORM_NAME">
</iframe>
<script src="https://links.growably.com/js/form_embed.js"></script>
```
 
Always ask the user for the GHL Form ID before writing this. Never invent one.