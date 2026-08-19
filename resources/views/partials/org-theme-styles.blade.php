<style>
    .org { font-family: 'Poppins', system-ui, sans-serif; color: #fff; }
    .org-label { font-size: 11px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase; color: #FF7000; }
    .org-muted { color: rgba(255,255,255,.65); }
    .org-card {
        background: #0a2537;
        border: 1px solid #1F2933;
        border-radius: 8px;
        height: 100%;
        display: flex;
        flex-direction: column;
        min-width: 0;
    }
    .org-card-pad { padding: 1.15rem 1.25rem; }
    .org-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    @media (min-width: 640px) {
        .org-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (min-width: 1100px) {
        .org-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    }
    .org-metric { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; font-size: 12.5px; }
    .org-metric + .org-metric { margin-top: 8px; }
    .org-metric-l { color: rgba(255,255,255,.65); }
    .org-metric-v { font-weight: 600; text-align: right; color: #fff; }
    .org-link { font-size: 12px; font-weight: 600; color: #FF7000; text-decoration: none; }
    .org-link:hover { color: #ff8a33; }
    .org-cta {
        display: inline-flex; align-items: center; justify-content: center;
        min-height: 40px; padding: 8px 16px; border: 1px solid #FF7000;
        color: #FF7000; font-size: 12px; font-weight: 600; text-decoration: none;
        border-radius: 4px; background: transparent; cursor: pointer;
    }
    .org-cta:hover { background: rgba(255,112,0,.12); color: #fff; }
    .org-hero-num { font-size: 1.75rem; font-weight: 700; line-height: 1.1; letter-spacing: -0.02em; color: #fff; }
    .org-hero-num.org-accent { color: #FF7000; }
    .org-hero-num.org-warn { color: #FACC15; }
    .org-stack { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; flex: 1; }
    .org-support-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 1rem;
    }
    @media (min-width: 800px) {
        .org-support-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    .org-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .org-table th {
        text-align: left; font-weight: 500; color: rgba(255,255,255,.55);
        padding: 8px 10px; border-bottom: 1px solid #1F2933; font-size: 11px;
        text-transform: uppercase; letter-spacing: .06em;
    }
    .org-table td {
        padding: 10px; border-bottom: 1px solid rgba(31,41,51,.85);
        vertical-align: top;
    }
    .org-table tr:last-child td { border-bottom: 0; }
    .org-chip {
        display: inline-flex; padding: 3px 8px; border: 1px solid #1F2933;
        border-radius: 4px; font-size: 11px; color: rgba(255,255,255,.75);
    }
    .org-range-btn {
        padding: 5px 10px; font-size: 11px; border: 1px solid #1F2933; border-radius: 4px;
        color: rgba(255,255,255,.55); background: transparent; cursor: pointer;
        min-height: 40px; min-width: 44px;
    }
    .org-range-btn.is-on { border-color: #FF7000; color: #FF7000; }
    .org-cta { width: 100%; }
    @media (min-width: 640px) {
        .org-cta { width: auto; }
    }
</style>
