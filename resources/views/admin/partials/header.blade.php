<div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
  <div>
    <div class="orange-rule"></div>
    <div class="heading-stack">
      <h1 class="section-heading-white">{{ $title }}</h1>
    </div>
  </div>
  @if(isset($action))
    <div>{!! $action !!}</div>
  @endif
</div>
