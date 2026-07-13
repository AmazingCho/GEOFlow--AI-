<select
    class="doc-switcher"
    aria-label="{{ $label('document_type', 'Document Type') }}"
    title="{{ $label('document_type', 'Document Type') }}"
    onchange="if(this.value) window.location.href=this.value"
>
    @foreach ($titles as $typeValue => $typeLabel)
        <option
            value="{{ route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id, 'type' => $typeValue, 'language' => $documentLanguage]) }}"
            @selected($documentKind === $typeValue)
        >{{ $typeLabel }}</option>
    @endforeach
</select>

<select
    class="doc-switcher"
    aria-label="{{ $label('output_language', 'Output Language') }}"
    title="{{ $label('output_language', 'Output Language') }}"
    onchange="if(this.value) window.location.href=this.value"
>
    @foreach ($languageOptions as $languageValue => $languageLabel)
        <option
            value="{{ route('admin.crm.quotes.print', ['quoteId' => (int) $quote->id, 'type' => $documentKind, 'language' => $languageValue]) }}"
            @selected($documentLanguage === $languageValue)
        >{{ $languageLabel }}</option>
    @endforeach
</select>

<a
    class="doc-action"
    href="{{ route('admin.crm.quotes.pdf', ['quoteId' => (int) $quote->id, 'type' => $documentKind, 'language' => $documentLanguage]) }}"
    data-generating-label="{{ $label('generating', 'Generating...') }}"
    onclick="this.textContent=this.dataset.generatingLabel;"
>{{ $label('download_pdf', 'Download PDF') }}</a>
