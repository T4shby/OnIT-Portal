@if($showEntra ?? true)
    <p class="portal-body-muted text-xs leading-relaxed">
        Tenant, group, and SCIM fields are in the <strong class="text-white/70">Entra</strong> section below on an existing client.
        Use <strong class="text-white/70">Connect Microsoft tenant</strong> on the checklist when onboarding.
    </p>
@else
    <p class="portal-body-muted text-xs leading-relaxed">
        After create, open the client and connect the Entra tenant (or paste IDs) on the edit form.
    </p>
@endif
