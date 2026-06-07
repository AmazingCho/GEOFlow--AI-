@include('admin.crm.quotes.partials.print-document', ['documentKind' => (string) ($documentKind ?? $quote->document_type ?? 'quotation')])
