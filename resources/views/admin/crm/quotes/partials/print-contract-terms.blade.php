<h2>{{ $label('contract_terms', 'Contract Terms') }}</h2>
<div class="section">{{ $quote->contract_terms ?: '-' }}</div>
@if ((string) ($quote->governing_law ?? '') !== '')
    <h2>{{ $label('governing_law', 'Governing Law') }}</h2>
    <div class="section">{{ $quote->governing_law }}</div>
@endif
@if ((string) ($quote->dispute_resolution ?? '') !== '')
    <h2>{{ $label('dispute_resolution', 'Dispute Resolution') }}</h2>
    <div class="section">{{ $quote->dispute_resolution }}</div>
@endif
<div class="muted" style="margin-top: 20px;">{{ $isZh ? '本合同模板仅作为商业草稿，发送前请人工审核。' : 'This contract template is a commercial draft and should be reviewed before sending.' }}</div>
