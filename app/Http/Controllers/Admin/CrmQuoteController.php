<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CrmCustomer;
use App\Models\CrmInquiry;
use App\Models\CrmOpportunity;
use App\Models\CrmQuote;
use App\Models\CrmQuoteItem;
use App\Models\CrmSellerProfile;
use App\Models\EntityRecord;
use App\Models\Image;
use App\Services\GeoFlow\CrmDocumentPdfService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\CollectionOptions;
use App\Support\GeoFlow\CrmDocumentLocale;
use App\Support\GeoFlow\CrmOptions;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class CrmQuoteController extends Controller
{
    private const PER_PAGE = 20;

    private const DOCUMENT_TYPES = ['quotation', 'proforma_invoice', 'invoice', 'contract', 'packing_list'];

    private const LINE_TYPES = ['product', 'accessory', 'service', 'spare_part', 'training', 'other'];

    private const SELLER_PROFILE_TYPES = ['seller_company', 'bank_account'];

    private const DEFAULT_WARRANTY_TERMS = '12 months warranty for machine main parts, excluding consumables and damage caused by misuse.';

    private const DEFAULT_INSTALLATION_TERMS = 'Installation & Training: Remote training and online technical support are included. On-site service is available at extra cost, with airfare, hotel and local travel expenses to be covered by the buyer.';

    /**
     * @return array<string, string>
     */
    private static function documentTypeOptions(): array
    {
        return [
            'quotation' => '报价单',
            'proforma_invoice' => '形式发票',
            'invoice' => '正式发票',
            'packing_list' => '装箱单',
            'contract' => '合同',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function lineTypeOptions(): array
    {
        return [
            'product' => '产品',
            'accessory' => '配件',
            'service' => '服务',
            'spare_part' => '备件',
            'training' => '培训',
            'other' => '其他',
        ];
    }

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $collectionId = $this->selectedCollectionId($request);

        $query = CrmQuote::query()
            ->with(['collection', 'customer', 'inquiry', 'opportunity'])
            ->withCount('items')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('quote_no', 'like', '%'.$search.'%')
                    ->orWhere('title', 'like', '%'.$search.'%')
                    ->orWhereHas('customer', static fn ($customerQuery) => $customerQuery->where('company_name', 'like', '%'.$search.'%'));
            });
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($collectionId !== null) {
            $query->where('collection_id', $collectionId);
        }

        return view('admin.crm.quotes.index', [
            'pageTitle' => '单据制作',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'quotes' => $query->paginate(self::PER_PAGE)->withQueryString(),
            'search' => $search,
            'status' => $status,
            'collectionId' => $collectionId,
            'collectionOptions' => CollectionOptions::all(),
            'stats' => [
                'total' => CrmQuote::query()->count(),
                'draft' => CrmQuote::query()->where('status', 'draft')->count(),
                'sent' => CrmQuote::query()->where('status', 'sent')->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $inquiry = null;
        $opportunity = null;
        $opportunityId = (int) $request->query('opportunity_id', 0);
        if ($opportunityId > 0) {
            $opportunity = CrmOpportunity::query()
                ->with(['customer', 'sourceInquiry.entities'])
                ->whereKey($opportunityId)
                ->first();
        }

        $inquiryId = (int) $request->query('inquiry_id', 0);
        if ($inquiryId <= 0 && $opportunity?->sourceInquiry) {
            $inquiryId = (int) $opportunity->sourceInquiry->id;
        }
        if ($inquiryId > 0) {
            $inquiry = CrmInquiry::query()->with(['customer', 'entities'])->whereKey($inquiryId)->first();
        }

        $collectionId = (int) ($request->query('collection_id', 0) ?: ($opportunity?->collection_id ?? $inquiry?->collection_id ?? 0));
        $customerId = (int) ($request->query('customer_id', 0) ?: ($opportunity?->customer_id ?? $inquiry?->customer_id ?? 0));
        $titleSource = $opportunity?->name ?: $inquiry?->subject;

        return view('admin.crm.quotes.form', $this->formData([
            'pageTitle' => '新增单据',
            'isEdit' => false,
            'quoteId' => 0,
            'quoteForm' => array_replace($this->emptyQuoteForm(), [
                'collection_id' => $collectionId > 0 ? (string) $collectionId : '',
                'customer_id' => $customerId > 0 ? (string) $customerId : '',
                'owner' => (string) ($inquiry?->assigned_to ?: $inquiry?->customer?->owner ?: ''),
                'inquiry_id' => (string) ((int) ($inquiry?->id ?? 0) ?: ''),
                'opportunity_id' => (string) ((int) ($opportunity?->id ?? 0) ?: ''),
                'title' => $titleSource ? 'Quotation - '.$titleSource : '',
            ]),
            'quoteItems' => $this->itemsFromInquiry($inquiry),
        ], $collectionId > 0 ? $collectionId : null, $customerId > 0 ? $customerId : null));
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateQuote($request);
        $this->applySalesChainToQuotePayload($payload);
        $this->validateQuoteItemMaterialScope($payload);
        $items = $this->normalizeItems($payload['items'] ?? [], $request);
        $quote = CrmQuote::query()->create($this->normalizeQuotePayload($payload, $items));
        $this->syncItems($quote, $items);

        return redirect()
            ->route('admin.crm.quotes.show', ['quoteId' => (int) $quote->id])
            ->with('message', '单据已创建');
    }

    public function show(int $quoteId): View
    {
        $quote = CrmQuote::query()
            ->with(['collection', 'customer', 'inquiry.customer.followUps.inquiry', 'opportunity', 'items.entity', 'items.image'])
            ->whereKey($quoteId)
            ->firstOrFail();

        return view('admin.crm.quotes.show', [
            'pageTitle' => '单据详情',
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'quote' => $quote,
            'languageOptions' => CrmDocumentLocale::options(),
        ]);
    }

    public function edit(int $quoteId): View
    {
        $quote = CrmQuote::query()->with(['items.entity', 'items.image'])->whereKey($quoteId)->firstOrFail();
        $collectionId = (int) ($quote->collection_id ?? 0) ?: null;
        $customerId = (int) ($quote->customer_id ?? 0) ?: null;

        return view('admin.crm.quotes.form', $this->formData([
            'pageTitle' => '编辑单据',
            'isEdit' => true,
            'quoteId' => (int) $quote->id,
            'quoteForm' => [
                'collection_id' => (string) ((int) ($quote->collection_id ?? 0) ?: ''),
                'customer_id' => (string) ((int) ($quote->customer_id ?? 0) ?: ''),
                'owner' => (string) ($quote->owner ?? ''),
                'inquiry_id' => (string) ((int) ($quote->inquiry_id ?? 0) ?: ''),
                'opportunity_id' => (string) ((int) ($quote->opportunity_id ?? 0) ?: ''),
                'quote_no' => (string) $quote->quote_no,
                'document_type' => (string) ($quote->document_type ?? 'quotation'),
                'title' => (string) $quote->title,
                'buyer_contact' => (string) ($quote->buyer_contact ?? ''),
                'buyer_company' => (string) ($quote->buyer_company ?? ''),
                'buyer_tax_number' => (string) ($quote->buyer_tax_number ?? ''),
                'buyer_phone' => (string) ($quote->buyer_phone ?? ''),
                'buyer_email' => (string) ($quote->buyer_email ?? ''),
                'buyer_address' => (string) ($quote->buyer_address ?? ''),
                'buyer_country' => (string) ($quote->buyer_country ?? ''),
                'document_language' => (string) ($quote->document_language ?? 'en'),
                'currency' => (string) ($quote->currency ?? 'USD'),
                'trade_term' => (string) ($quote->trade_term ?? ''),
                'port_of_loading' => (string) ($quote->port_of_loading ?? ''),
                'port_of_destination' => (string) ($quote->port_of_destination ?? ''),
                'transport_mode' => (string) ($quote->transport_mode ?? ''),
                'shipping_mark' => (string) ($quote->shipping_mark ?? ''),
                'origin_country' => (string) ($quote->origin_country ?? ''),
                'valid_until' => $quote->valid_until ? $quote->valid_until->format('Y-m-d') : '',
                'payment_terms' => (string) ($quote->payment_terms ?? ''),
                'delivery_terms' => (string) ($quote->delivery_terms ?? ''),
                'lead_time' => (string) ($quote->lead_time ?? ''),
                'warranty_terms' => (string) ($quote->warranty_terms ?? ''),
                'installation_terms' => (string) ($quote->installation_terms ?? ''),
                'status' => (string) ($quote->status ?? 'draft'),
                'notes' => (string) ($quote->notes ?? ''),
                'internal_notes' => (string) ($quote->internal_notes ?? ''),
                'shipping_fee' => (string) ($quote->shipping_fee ?? '0'),
                'discount_amount' => (string) ($quote->discount_amount ?? '0'),
                'tax_amount' => (string) ($quote->tax_amount ?? '0'),
                'grand_total' => (string) ($quote->grand_total ?? $quote->total_amount ?? '0'),
                'bank_account_json' => $this->encodeJsonForForm($quote->bank_account_json),
                'seller_company_json' => $this->encodeJsonForForm($quote->seller_company_json),
                'signature_notes' => (string) ($quote->signature_notes ?? ''),
                'contract_terms' => (string) ($quote->contract_terms ?? ''),
                'governing_law' => (string) ($quote->governing_law ?? ''),
                'dispute_resolution' => (string) ($quote->dispute_resolution ?? ''),
            ],
            'quoteItems' => $quote->items->map(static fn (CrmQuoteItem $item): array => [
                'entity_id' => (string) ((int) ($item->entity_id ?? 0) ?: ''),
                'line_type' => (string) ($item->line_type ?? 'product'),
                'model' => (string) ($item->model ?? ''),
                'hs_code' => (string) ($item->hs_code ?? ''),
                'image_id' => (string) ((int) ($item->image_id ?? 0) ?: ''),
                'image_path' => (string) ($item->image_path ?? ''),
                'image_original_name' => (string) ($item->image_original_name ?? ''),
                'item_name' => (string) $item->item_name,
                'description' => (string) ($item->description ?? ''),
                'quantity' => (string) $item->quantity,
                'unit' => (string) ($item->unit ?? ''),
                'unit_price' => (string) $item->unit_price,
                'package_count' => (string) ($item->package_count ?? '0'),
                'net_weight' => (string) ($item->net_weight ?? '0'),
                'gross_weight' => (string) ($item->gross_weight ?? '0'),
                'volume_cbm' => (string) ($item->volume_cbm ?? '0'),
                'package_length' => (string) ($item->package_length ?? ''),
                'package_width' => (string) ($item->package_width ?? ''),
                'package_height' => (string) ($item->package_height ?? ''),
            ])->all(),
        ], $collectionId, $customerId));
    }

    public function update(Request $request, int $quoteId): RedirectResponse
    {
        $quote = CrmQuote::query()->whereKey($quoteId)->firstOrFail();
        $payload = $this->validateQuote($request, $quote);
        $this->applySalesChainToQuotePayload($payload);
        $this->validateQuoteItemMaterialScope($payload);
        $items = $this->normalizeItems($payload['items'] ?? [], $request);
        $quote->update($this->normalizeQuotePayload($payload, $items, $quote));
        $this->syncItems($quote, $items);

        return redirect()
            ->route('admin.crm.quotes.edit', ['quoteId' => (int) $quote->id])
            ->with('message', '单据已更新');
    }

    public function destroy(int $quoteId): RedirectResponse
    {
        CrmQuote::query()->whereKey($quoteId)->firstOrFail()->delete();

        return redirect()
            ->route('admin.crm.quotes.index')
            ->with('message', '单据已归档');
    }

    public function convert(Request $request, int $quoteId): RedirectResponse
    {
        $source = CrmQuote::query()->with('items')->findOrFail($quoteId);
        $data = $request->validate(['document_type' => ['required', 'string', Rule::in(self::DOCUMENT_TYPES)]]);
        $targetType = (string) $data['document_type'];
        $copy = $source->replicate(['quote_no', 'document_type', 'status', 'revision', 'created_at', 'updated_at']);
        $copy->fill(['quote_no' => $this->generateQuoteNo(), 'document_type' => $targetType, 'source_quote_id' => $source->id, 'title' => ($this->documentTypeOptions()[$targetType] ?? $targetType).' - '.$source->title, 'status' => 'draft', 'revision' => 1]);
        $copy->save();
        foreach ($source->items as $item) {
            $newItem = $item->replicate(['quote_id', 'created_at', 'updated_at']);
            $newItem->quote_id = $copy->id;
            $newItem->save();
        }

        return redirect()->route('admin.crm.quotes.edit', ['quoteId' => $copy->id])->with('message', '已创建独立单据，请核对后保存');
    }

    public function storeSellerProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(self::SELLER_PROFILE_TYPES)],
            'name' => ['required', 'string', 'max:160'],
            'payload' => ['required', 'string', 'max:20000', 'json'],
            'set_default' => ['nullable', 'boolean'],
        ]);

        $payloadText = trim((string) $data['payload']);
        $payload = json_decode($payloadText, true);
        if (! str_starts_with($payloadText, '{') || ! is_array($payload)) {
            return response()->json([
                'message' => 'JSON 必须是对象格式，例如 {"name":"..."}。',
            ], 422);
        }

        $type = (string) $data['type'];
        $name = trim((string) $data['name']);
        $setDefault = (bool) ($data['set_default'] ?? false);

        if ($setDefault) {
            CrmSellerProfile::query()->where('type', $type)->update(['is_default' => false]);
        }

        $profile = CrmSellerProfile::query()->firstOrNew([
            'type' => $type,
            'name' => $name,
        ]);
        $profile->payload = $payload;
        if (! $profile->exists) {
            $profile->created_by_admin_id = (int) ($request->user('admin')?->id ?? 0) ?: null;
            $profile->is_default = false;
        }
        if ($setDefault) {
            $profile->is_default = true;
        }
        $profile->save();

        return response()->json([
            'message' => '常用信息已保存',
            'profile' => $this->sellerProfileOption($profile),
        ]);
    }

    public function setDefaultSellerProfile(Request $request, int $profileId): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(self::SELLER_PROFILE_TYPES)],
        ]);

        $type = (string) $data['type'];
        $profile = CrmSellerProfile::query()
            ->whereKey($profileId)
            ->where('type', $type)
            ->firstOrFail();

        CrmSellerProfile::query()->where('type', $type)->update(['is_default' => false]);
        $profile->forceFill(['is_default' => true])->save();

        return response()->json([
            'message' => '默认常用信息已更新',
            'profile' => $this->sellerProfileOption($profile->refresh()),
        ]);
    }

    public function print(int $quoteId, Request $request): View
    {
        $quote = CrmQuote::query()
            ->with(['collection', 'customer', 'inquiry.customer.followUps.inquiry', 'opportunity', 'items.entity', 'items.image'])
            ->whereKey($quoteId)
            ->firstOrFail();

        $documentType = $this->resolvedDocumentType($request, $quote);
        $documentLanguage = $this->resolvedDocumentLanguage($request, $quote);
        $view = $this->printViewForDocumentType($documentType);

        return view($view, [
            'pageTitle' => $quote->quote_no.' - '.($this->documentTypeOptions()[$documentType] ?? $documentType),
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'quote' => $quote,
            'seller' => $this->sellerProfile($quote),
            'documentKind' => $documentType,
            'documentLanguage' => $documentLanguage,
            'documentLanguageOptions' => CrmDocumentLocale::options(),
            'documentTitles' => CrmDocumentLocale::documentTitles($documentLanguage),
            'documentLabels' => CrmDocumentLocale::labels($documentLanguage),
        ]);
    }

    public function downloadPdf(int $quoteId, Request $request, CrmDocumentPdfService $pdfService)
    {
        $quote = CrmQuote::query()
            ->with(['collection', 'customer', 'inquiry.customer.followUps.inquiry', 'opportunity', 'items.entity', 'items.image'])
            ->whereKey($quoteId)
            ->firstOrFail();

        $documentType = $this->resolvedDocumentType($request, $quote);
        $documentLanguage = $this->resolvedDocumentLanguage($request, $quote);
        $view = $this->printViewForDocumentType($documentType);
        $fileName = $this->pdfDownloadName($quote, $documentType);

        try {
            $html = view($view, [
                'pageTitle' => $quote->quote_no.' - '.($this->documentTypeOptions()[$documentType] ?? $documentType),
                'activeMenu' => 'crm',
                'adminSiteName' => AdminWeb::siteName(),
                'quote' => $quote,
                'seller' => $this->sellerProfile($quote),
                'documentKind' => $documentType,
                'documentLanguage' => $documentLanguage,
                'documentLanguageOptions' => CrmDocumentLocale::options(),
                'documentTitles' => CrmDocumentLocale::documentTitles($documentLanguage),
                'documentLabels' => CrmDocumentLocale::labels($documentLanguage),
            ])->render();

            $pdfPath = $pdfService->render($html, pathinfo($fileName, PATHINFO_FILENAME));
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.crm.quotes.print', [
                    'quoteId' => (int) $quote->id,
                    'type' => $documentType,
                    'language' => $documentLanguage,
                ])
                ->with('error', 'PDF 生成失败，请先使用打印预览手动保存为 PDF。');
        }

        return response()
            ->download($pdfPath, $fileName, ['Content-Type' => 'application/pdf'])
            ->deleteFileAfterSend(true);
    }

    private function resolvedDocumentType(Request $request, CrmQuote $quote): string
    {
        $documentType = (string) ($request->query('type', $quote->document_type ?? 'quotation'));

        return in_array($documentType, self::DOCUMENT_TYPES, true)
            ? $documentType
            : (string) ($quote->document_type ?? 'quotation');
    }

    private function resolvedDocumentLanguage(Request $request, CrmQuote $quote): string
    {
        $requested = $request->query('language');

        return CrmDocumentLocale::resolve(
            is_string($requested) ? $requested : null,
            (string) ($quote->document_language ?? 'en'),
        );
    }

    private function printViewForDocumentType(string $documentType): string
    {
        return match ($documentType) {
            'proforma_invoice' => 'admin.crm.quotes.print-proforma-invoice',
            'invoice' => 'admin.crm.quotes.print-invoice',
            'packing_list' => 'admin.crm.quotes.print-packing-list',
            'contract' => 'admin.crm.quotes.print-contract',
            default => 'admin.crm.quotes.print-quotation',
        };
    }

    private function pdfDownloadName(CrmQuote $quote, string $documentType): string
    {
        $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim((string) $quote->quote_no.'-'.$documentType));
        $stem = trim((string) $stem, '-_.');

        return ($stem !== '' ? $stem : 'crm-document').'.pdf';
    }

    private function formData(array $base, ?int $collectionId, ?int $customerId): array
    {
        $sellerCompanyProfiles = $this->sellerProfileOptions('seller_company');
        $bankAccountProfiles = $this->sellerProfileOptions('bank_account');
        $entityOptions = $this->entityOptions($collectionId);
        $imageOptions = $this->imageOptions($collectionId);
        $entityOptionPool = $collectionId !== null && $collectionId > 0 ? $this->entityOptions(null) : $entityOptions;
        $imageOptionPool = $collectionId !== null && $collectionId > 0 ? $this->imageOptions(null) : $imageOptions;

        return $base + [
            'activeMenu' => 'crm',
            'adminSiteName' => AdminWeb::siteName(),
            'collectionOptions' => CollectionOptions::all(true),
            'customerOptions' => $this->customerOptions($collectionId),
            'customerProfiles' => $this->customerProfiles($collectionId),
            'inquiryOptions' => $this->inquiryOptions($collectionId, $customerId),
            'opportunityOptions' => $this->opportunityOptions($collectionId, $customerId),
            'entityOptions' => $entityOptions,
            'imageOptions' => $imageOptions,
            'quoteEntityOptionPool' => $entityOptionPool,
            'quoteImageOptionPool' => $imageOptionPool,
            'employeeOptions' => CrmOptions::employeeOptions(),
            'documentTypeOptions' => self::documentTypeOptions(),
            'lineTypeOptions' => self::lineTypeOptions(),
            'languageOptions' => CrmDocumentLocale::options(),
            'sellerCompanyProfileOptions' => $sellerCompanyProfiles,
            'bankAccountProfileOptions' => $bankAccountProfiles,
            'defaultSellerCompanyJson' => $this->defaultSellerProfileJson('seller_company', $sellerCompanyProfiles, [
                'name' => 'Robota Automation',
                'address' => 'Songang, Baoan, Shenzhen, China',
                'phone' => '008615018549304',
                'email' => 'sales@robotadispensing.com',
                'website' => 'https://robotadispensing.com',
            ]),
            'defaultBankAccountJson' => $this->defaultSellerProfileJson('bank_account', $bankAccountProfiles, [
                'beneficiary' => '',
                'bank_name' => '',
                'account_no' => '',
                'bank_code' => '',
                'branch_code' => '',
                'swift' => '',
                'bank_address' => '',
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateQuote(Request $request, ?CrmQuote $quote = null): array
    {
        return $request->validate([
            'collection_id' => ['nullable', 'integer', 'min:1', Rule::exists('collections', 'id')],
            'customer_id' => ['required', 'integer', 'min:1', Rule::exists('crm_customers', 'id')],
            'owner' => ['nullable', 'string', 'max:120'],
            'inquiry_id' => ['nullable', 'integer', 'min:1', Rule::exists('crm_inquiries', 'id')],
            'opportunity_id' => ['nullable', 'integer', 'min:1', Rule::exists('crm_opportunities', 'id')],
            'quote_no' => ['nullable', 'string', 'max:80', Rule::unique('crm_quotes', 'quote_no')->ignore($quote?->id)],
            'document_type' => ['nullable', 'string', Rule::in(self::DOCUMENT_TYPES)],
            'title' => ['required', 'string', 'max:200'],
            'buyer_contact' => ['nullable', 'string', 'max:200'],
            'buyer_company' => ['nullable', 'string', 'max:200'],
            'buyer_tax_number' => ['nullable', 'string', 'max:120'],
            'buyer_phone' => ['nullable', 'string', 'max:120'],
            'buyer_email' => ['nullable', 'email', 'max:200'],
            'buyer_address' => ['nullable', 'string', 'max:10000'],
            'buyer_country' => ['nullable', 'string', 'max:100'],
            'document_language' => ['nullable', 'string', Rule::in(CrmDocumentLocale::supported())],
            'currency' => ['nullable', 'string', 'max:10'],
            'trade_term' => ['nullable', 'string', 'max:80'],
            'port_of_loading' => ['nullable', 'string', 'max:200'],
            'port_of_destination' => ['nullable', 'string', 'max:200'],
            'transport_mode' => ['nullable', 'string', 'max:100'],
            'shipping_mark' => ['nullable', 'string', 'max:500'],
            'origin_country' => ['nullable', 'string', 'max:100'],
            'valid_until' => ['nullable', 'date'],
            'payment_terms' => ['nullable', 'string', 'max:10000'],
            'delivery_terms' => ['nullable', 'string', 'max:10000'],
            'lead_time' => ['nullable', 'string', 'max:120'],
            'warranty_terms' => ['nullable', 'string', 'max:10000'],
            'installation_terms' => ['nullable', 'string', 'max:10000'],
            'packing_terms' => ['nullable', 'string', 'max:500'],
            'deposit_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'sent', 'accepted', 'rejected', 'expired'])],
            'notes' => ['nullable', 'string', 'max:20000'],
            'internal_notes' => ['nullable', 'string', 'max:20000'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_account_json' => ['nullable', 'string', 'max:20000', 'json'],
            'seller_company_json' => ['nullable', 'string', 'max:20000', 'json'],
            'signature_notes' => ['nullable', 'string', 'max:10000'],
            'contract_terms' => ['nullable', 'string', 'max:30000'],
            'governing_law' => ['nullable', 'string', 'max:160'],
            'dispute_resolution' => ['nullable', 'string', 'max:10000'],
            'items' => ['nullable', 'array'],
            'items.entity_id' => ['nullable', 'array'],
            'items.entity_id.*' => ['nullable', 'integer', 'min:1', Rule::exists('entities', 'id')],
            'items.line_type' => ['nullable', 'array'],
            'items.line_type.*' => ['nullable', 'string', Rule::in(self::LINE_TYPES)],
            'items.model' => ['nullable', 'array'],
            'items.model.*' => ['nullable', 'string', 'max:120'],
            'items.hs_code' => ['nullable', 'array'],
            'items.hs_code.*' => ['nullable', 'string', 'max:80'],
            'items.image_id' => ['nullable', 'array'],
            'items.image_id.*' => ['nullable', 'integer', 'min:1', Rule::exists('images', 'id')],
            'items.image_path' => ['nullable', 'array'],
            'items.image_path.*' => ['nullable', 'string', 'max:500'],
            'items.image_original_name' => ['nullable', 'array'],
            'items.image_original_name.*' => ['nullable', 'string', 'max:200'],
            'items.image_upload' => ['nullable', 'array'],
            'items.image_upload.*' => ['nullable', File::types(['jpg', 'jpeg', 'png', 'webp'])->max(200)],
            'items.item_name' => ['nullable', 'array'],
            'items.item_name.*' => ['nullable', 'string', 'max:200'],
            'items.description' => ['nullable', 'array'],
            'items.description.*' => ['nullable', 'string', 'max:10000'],
            'items.quantity' => ['nullable', 'array'],
            'items.quantity.*' => ['nullable', 'numeric', 'min:0'],
            'items.unit' => ['nullable', 'array'],
            'items.unit.*' => ['nullable', 'string', 'max:40'],
            'items.unit_price' => ['nullable', 'array'],
            'items.unit_price.*' => ['nullable', 'numeric', 'min:0'],
            'items.package_count' => ['nullable', 'array'],
            'items.package_count.*' => ['nullable', 'integer', 'min:0'],
            'items.net_weight' => ['nullable', 'array'],
            'items.net_weight.*' => ['nullable', 'numeric', 'min:0'],
            'items.gross_weight' => ['nullable', 'array'],
            'items.gross_weight.*' => ['nullable', 'numeric', 'min:0'],
            'items.volume_cbm' => ['nullable', 'array'],
            'items.volume_cbm.*' => ['nullable', 'numeric', 'min:0'],
            'items.package_length' => ['nullable', 'array'],
            'items.package_length.*' => ['nullable', 'numeric', 'min:0'],
            'items.package_width' => ['nullable', 'array'],
            'items.package_width.*' => ['nullable', 'numeric', 'min:0'],
            'items.package_height' => ['nullable', 'array'],
            'items.package_height.*' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function normalizeQuotePayload(array $payload, array $items, ?CrmQuote $quote = null): array
    {
        $customerDefaults = $this->customerBuyerDefaults((int) $payload['customer_id']);
        $itemsSubtotal = round((float) collect($items)->sum('amount'), 2);
        $shippingFee = $this->normalizeMoney($payload['shipping_fee'] ?? 0);
        $discountAmount = $this->normalizeMoney($payload['discount_amount'] ?? 0);
        $taxAmount = $this->normalizeMoney($payload['tax_amount'] ?? 0);

        return [
            'collection_id' => $this->normalizeNullableId($payload['collection_id'] ?? null),
            'customer_id' => (int) $payload['customer_id'],
            'owner' => trim((string) ($payload['owner'] ?? '')),
            'inquiry_id' => $this->normalizeNullableId($payload['inquiry_id'] ?? null),
            'opportunity_id' => $this->normalizeNullableId($payload['opportunity_id'] ?? null),
            'quote_no' => trim((string) ($payload['quote_no'] ?? '')) !== '' ? trim((string) $payload['quote_no']) : ($quote?->quote_no ?: $this->generateQuoteNo()),
            'document_type' => in_array((string) ($payload['document_type'] ?? ''), self::DOCUMENT_TYPES, true) ? (string) $payload['document_type'] : 'quotation',
            'title' => trim((string) $payload['title']),
            'buyer_contact' => trim((string) ($payload['buyer_contact'] ?? '')) ?: $customerDefaults['buyer_contact'],
            'buyer_company' => trim((string) ($payload['buyer_company'] ?? '')) ?: $customerDefaults['buyer_company'],
            'buyer_tax_number' => trim((string) ($payload['buyer_tax_number'] ?? '')) ?: $customerDefaults['buyer_tax_number'],
            'buyer_phone' => trim((string) ($payload['buyer_phone'] ?? '')) ?: $customerDefaults['buyer_phone'],
            'buyer_email' => trim((string) ($payload['buyer_email'] ?? '')) ?: $customerDefaults['buyer_email'],
            'buyer_address' => trim((string) ($payload['buyer_address'] ?? '')) ?: $customerDefaults['buyer_address'],
            'buyer_country' => trim((string) ($payload['buyer_country'] ?? '')) ?: $customerDefaults['buyer_country'],
            'document_language' => CrmDocumentLocale::resolve(
                is_string($payload['document_language'] ?? null) ? $payload['document_language'] : null,
                'en',
            ),
            'currency' => strtoupper(trim((string) ($payload['currency'] ?? 'USD'))) ?: 'USD',
            'trade_term' => trim((string) ($payload['trade_term'] ?? '')),
            'port_of_loading' => trim((string) ($payload['port_of_loading'] ?? '')),
            'port_of_destination' => trim((string) ($payload['port_of_destination'] ?? '')),
            'transport_mode' => trim((string) ($payload['transport_mode'] ?? '')),
            'shipping_mark' => trim((string) ($payload['shipping_mark'] ?? '')),
            'origin_country' => trim((string) ($payload['origin_country'] ?? '')),
            'valid_until' => $payload['valid_until'] ?? null,
            'payment_terms' => trim((string) ($payload['payment_terms'] ?? '')),
            'delivery_terms' => trim((string) ($payload['delivery_terms'] ?? '')),
            'lead_time' => trim((string) ($payload['lead_time'] ?? '')),
            'warranty_terms' => trim((string) ($payload['warranty_terms'] ?? '')),
            'installation_terms' => trim((string) ($payload['installation_terms'] ?? '')),
            'status' => (string) ($payload['status'] ?? 'draft'),
            'notes' => trim((string) ($payload['notes'] ?? '')),
            'internal_notes' => trim((string) ($payload['internal_notes'] ?? '')),
            'total_amount' => $itemsSubtotal,
            'shipping_fee' => $shippingFee,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'grand_total' => round($itemsSubtotal + $shippingFee + $taxAmount - $discountAmount, 2),
            'bank_account_json' => $this->normalizeJsonPayload($payload['bank_account_json'] ?? null),
            'seller_company_json' => $this->normalizeJsonPayload($payload['seller_company_json'] ?? null),
            'signature_notes' => trim((string) ($payload['signature_notes'] ?? '')),
            'contract_terms' => trim((string) ($payload['contract_terms'] ?? '')),
            'governing_law' => trim((string) ($payload['governing_law'] ?? '')),
            'dispute_resolution' => trim((string) ($payload['dispute_resolution'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function applySalesChainToQuotePayload(array &$payload): void
    {
        $customerId = (int) ($payload['customer_id'] ?? 0);
        $inquiryId = $this->normalizeNullableId($payload['inquiry_id'] ?? null);
        $opportunityId = $this->normalizeNullableId($payload['opportunity_id'] ?? null);
        $collectionId = $this->normalizeNullableId($payload['collection_id'] ?? null);

        $inquiry = $inquiryId ? CrmInquiry::query()->findOrFail($inquiryId) : null;
        $opportunity = $opportunityId ? CrmOpportunity::query()->findOrFail($opportunityId) : null;

        if ($inquiry && ! $opportunity) {
            $opportunity = CrmOpportunity::query()
                ->where('source_inquiry_id', $inquiry->id)
                ->oldest('id')
                ->first();
            $opportunityId = $opportunity?->id;
        }
        if ($opportunity && ! $inquiry && $opportunity->source_inquiry_id) {
            $inquiry = CrmInquiry::query()->find((int) $opportunity->source_inquiry_id);
            $inquiryId = $inquiry?->id;
        }

        if ($inquiry && $opportunity && (int) $opportunity->source_inquiry_id !== (int) $inquiry->id) {
            throw ValidationException::withMessages([
                'opportunity_id' => '关联商机与关联询盘不是同一条销售链。',
            ]);
        }

        $expectedCustomerId = (int) ($opportunity?->customer_id ?: $inquiry?->customer_id ?: 0);
        if ($expectedCustomerId > 0 && $customerId !== $expectedCustomerId) {
            throw ValidationException::withMessages([
                'customer_id' => '单据客户必须与关联询盘或商机客户一致。',
            ]);
        }

        $expectedCollectionId = (int) ($opportunity?->collection_id ?: $inquiry?->collection_id ?: 0) ?: null;
        if ($expectedCollectionId !== null && $collectionId !== null && $collectionId !== $expectedCollectionId) {
            throw ValidationException::withMessages([
                'collection_id' => '单据业务容器必须与关联询盘或商机一致。',
            ]);
        }

        $payload['customer_id'] = $expectedCustomerId > 0 ? $expectedCustomerId : $customerId;
        $payload['inquiry_id'] = $inquiryId;
        $payload['opportunity_id'] = $opportunityId;
        $payload['collection_id'] = $expectedCollectionId ?? $collectionId;
    }

    /** @param array<string, mixed> $payload */
    private function validateQuoteItemMaterialScope(array $payload): void
    {
        $collectionId = $this->normalizeNullableId($payload['collection_id'] ?? null);
        if ($collectionId === null) {
            return;
        }

        $itemsPayload = (array) ($payload['items'] ?? []);
        $entityIds = collect((array) ($itemsPayload['entity_id'] ?? []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($entityIds->isNotEmpty()) {
            $hasOutOfScopeEntity = EntityRecord::query()
                ->whereIn('id', $entityIds->all())
                ->where(static function ($query) use ($collectionId): void {
                    $query->whereNull('collection_id')
                        ->orWhere('collection_id', '<>', $collectionId);
                })
                ->exists();

            if ($hasOutOfScopeEntity) {
                throw ValidationException::withMessages([
                    'items.entity_id' => '明细关联 Entity 必须属于当前业务容器。',
                ]);
            }
        }

        $imageIds = collect((array) ($itemsPayload['image_id'] ?? []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($imageIds->isEmpty()) {
            return;
        }

        $hasOutOfScopeImage = Image::query()
            ->whereIn('id', $imageIds->all())
            ->where(static function ($query) use ($collectionId): void {
                $query->whereDoesntHave('library')
                    ->orWhereHas('library', static function ($libraryQuery) use ($collectionId): void {
                        $libraryQuery->whereNull('collection_id')
                            ->orWhere('collection_id', '<>', $collectionId);
                    });
            })
            ->exists();

        if ($hasOutOfScopeImage) {
            throw ValidationException::withMessages([
                'items.image_id' => '明细图片必须来自当前业务容器下的图片库。',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $itemsPayload
     * @return list<array<string, mixed>>
     */
    private function normalizeItems(array $itemsPayload, Request $request): array
    {
        $names = $itemsPayload['item_name'] ?? [];
        /** @var array<int, UploadedFile|null> $uploadedImages */
        $uploadedImages = (array) $request->file('items.image_upload', []);
        $rows = [];
        foreach ((array) $names as $index => $name) {
            $itemName = trim((string) $name);
            if ($itemName === '') {
                continue;
            }
            $quantity = max(0, (float) (($itemsPayload['quantity'][$index] ?? 1) ?: 1));
            $unitPrice = max(0, (float) (($itemsPayload['unit_price'][$index] ?? 0) ?: 0));
            $imageId = $this->normalizeNullableId($itemsPayload['image_id'][$index] ?? null);
            $imagePath = trim((string) ($itemsPayload['image_path'][$index] ?? ''));
            $imageOriginalName = trim((string) ($itemsPayload['image_original_name'][$index] ?? ''));
            $uploadedImage = $uploadedImages[$index] ?? null;
            if ($uploadedImage instanceof UploadedFile && $uploadedImage->isValid()) {
                $storedImage = $this->storeQuoteUploadedImage($uploadedImage);
                $imageId = null;
                $imagePath = $storedImage['file_path'];
                $imageOriginalName = $storedImage['original_name'];
            } elseif ($imageId !== null) {
                $imagePath = '';
                $imageOriginalName = '';
            }
            $rows[] = [
                'entity_id' => $this->normalizeNullableId($itemsPayload['entity_id'][$index] ?? null),
                'line_type' => $this->normalizeLineType($itemsPayload['line_type'][$index] ?? 'product'),
                'model' => trim((string) ($itemsPayload['model'][$index] ?? '')),
                'hs_code' => trim((string) ($itemsPayload['hs_code'][$index] ?? '')),
                'image_id' => $imageId,
                'image_path' => $imagePath,
                'image_original_name' => $imageOriginalName,
                'item_name' => $itemName,
                'description' => trim((string) ($itemsPayload['description'][$index] ?? '')),
                'quantity' => $quantity,
                'unit' => trim((string) ($itemsPayload['unit'][$index] ?? '')),
                'unit_price' => $unitPrice,
                'amount' => round($quantity * $unitPrice, 2),
                'package_count' => max(0, (int) (($itemsPayload['package_count'][$index] ?? 0) ?: 0)),
                'net_weight' => max(0, (float) (($itemsPayload['net_weight'][$index] ?? 0) ?: 0)),
                'gross_weight' => max(0, (float) (($itemsPayload['gross_weight'][$index] ?? 0) ?: 0)),
                'volume_cbm' => max(0, (float) (($itemsPayload['volume_cbm'][$index] ?? 0) ?: 0)),
                'package_length' => ((float) ($itemsPayload['package_length'][$index] ?? 0)) > 0 ? (float) ($itemsPayload['package_length'][$index] ?? 0) : null,
                'package_width' => ((float) ($itemsPayload['package_width'][$index] ?? 0)) > 0 ? (float) ($itemsPayload['package_width'][$index] ?? 0) : null,
                'package_height' => ((float) ($itemsPayload['package_height'][$index] ?? 0)) > 0 ? (float) ($itemsPayload['package_height'][$index] ?? 0) : null,
                'sort_order' => count($rows) + 1,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function syncItems(CrmQuote $quote, array $items): void
    {
        $quote->items()->delete();
        foreach ($items as $item) {
            $quote->items()->create($item);
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function itemsFromInquiry(?CrmInquiry $inquiry): array
    {
        if (! $inquiry || ! $inquiry->relationLoaded('entities')) {
            return [$this->emptyQuoteItem()];
        }

        $items = $inquiry->entities->map(static fn (EntityRecord $entity): array => [
            'entity_id' => (string) $entity->id,
            'line_type' => 'product',
            'model' => '',
            'hs_code' => '',
            'image_id' => '',
            'image_path' => '',
            'image_original_name' => '',
            'item_name' => (string) $entity->name,
            'description' => (string) ($entity->description ?? ''),
            'quantity' => '1',
            'unit' => 'set',
            'unit_price' => '0',
            'package_count' => '0',
            'net_weight' => '0',
            'gross_weight' => '0',
            'volume_cbm' => '0',
            'package_length' => '',
            'package_width' => '',
            'package_height' => '',
        ])->values()->all();

        return $items !== [] ? $items : [$this->emptyQuoteItem()];
    }

    /**
     * @return array<string, string>
     */
    private function emptyQuoteForm(): array
    {
        return [
            'collection_id' => '',
            'customer_id' => '',
            'owner' => '',
            'inquiry_id' => '',
            'opportunity_id' => '',
            'quote_no' => '',
            'document_type' => 'quotation',
            'title' => '',
            'buyer_company' => '',
            'buyer_contact' => '',
            'buyer_tax_number' => '',
            'buyer_phone' => '',
            'buyer_email' => '',
            'buyer_address' => '',
            'buyer_country' => '',
            'document_language' => 'en',
            'currency' => 'USD',
            'trade_term' => '',
            'port_of_loading' => '',
            'port_of_destination' => '',
            'transport_mode' => '',
            'shipping_mark' => '',
            'origin_country' => '',
            'valid_until' => '',
            'payment_terms' => '',
            'delivery_terms' => '',
            'lead_time' => '',
            'warranty_terms' => self::DEFAULT_WARRANTY_TERMS,
            'installation_terms' => self::DEFAULT_INSTALLATION_TERMS,
            'packing_terms' => '',
            'deposit_percent' => 60,
            'status' => 'draft',
            'notes' => '',
            'internal_notes' => '',
            'shipping_fee' => '0',
            'discount_amount' => '0',
            'tax_amount' => '0',
            'grand_total' => '0',
            'bank_account_json' => '',
            'seller_company_json' => '',
            'signature_notes' => '',
            'contract_terms' => '',
            'governing_law' => '',
            'dispute_resolution' => '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function emptyQuoteItem(): array
    {
        return [
            'entity_id' => '',
            'line_type' => 'product',
            'model' => '',
            'hs_code' => '',
            'image_id' => '',
            'image_path' => '',
            'image_original_name' => '',
            'item_name' => '',
            'description' => '',
            'quantity' => '1',
            'unit' => '',
            'unit_price' => '0',
            'package_count' => '0',
            'net_weight' => '0',
            'gross_weight' => '0',
            'volume_cbm' => '0',
            'package_length' => '',
            'package_width' => '',
            'package_height' => '',
        ];
    }

    private function generateQuoteNo(): string
    {
        return 'Q-'.date('Ymd-His').'-'.Str::upper(Str::random(4));
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function normalizeMoney(mixed $value): float
    {
        return round(max(0, (float) ($value ?: 0)), 2);
    }

    private function normalizeLineType(mixed $value): string
    {
        $lineType = (string) ($value ?: 'product');

        return in_array($lineType, self::LINE_TYPES, true) ? $lineType : 'product';
    }

    /**
     * @return array<string, string>
     */
    private function customerBuyerDefaults(int $customerId): array
    {
        $customer = CrmCustomer::query()->whereKey($customerId)->first();

        return [
            'buyer_company' => (string) ($customer?->company_name ?? ''),
            'buyer_phone' => (string) ($customer?->phone ?? ''),
            'buyer_country' => (string) ($customer?->country ?? ''),
            'buyer_contact' => (string) ($customer?->contact_person ?? ''),
            'buyer_address' => (string) ($customer?->address ?? ''),
            'buyer_email' => (string) ($customer?->email ?? ''),
            'buyer_tax_number' => (string) ($customer?->tax_number ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeJsonPayload(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : ['raw' => $text];
    }

    private function encodeJsonForForm(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return '';
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<array{id:int,name:string,payload:string,is_default:bool}>
     */
    private function sellerProfileOptions(string $type): array
    {
        return CrmSellerProfile::query()
            ->where('type', $type)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (CrmSellerProfile $profile): array => $this->sellerProfileOption($profile))
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,name:string,payload:string,is_default:bool}
     */
    private function sellerProfileOption(CrmSellerProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'name' => (string) $profile->name,
            'payload' => $this->encodeJsonForForm($profile->payload),
            'is_default' => (bool) $profile->is_default,
        ];
    }

    /**
     * @param  list<array{id:int,name:string,payload:string,is_default:bool}>  $profiles
     * @param  array<string, mixed>  $fallback
     */
    private function defaultSellerProfileJson(string $type, array $profiles, array $fallback): string
    {
        foreach ($profiles as $profile) {
            if ((bool) ($profile['is_default'] ?? false)) {
                return (string) $profile['payload'];
            }
        }

        return $this->encodeJsonForForm($fallback);
    }

    /**
     * @return array{file_path:string,original_name:string}
     */
    private function storeQuoteUploadedImage(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = 'quote-'.date('YmdHis').'-'.Str::lower(Str::random(10)).'.'.$extension;
        $directory = 'uploads/crm-documents/'.date('Y/m');
        if (! Storage::disk('public')->exists($directory)) {
            Storage::disk('public')->makeDirectory($directory);
        }

        $storedRelativePath = Storage::disk('public')->putFileAs($directory, $file, $filename);
        if (! is_string($storedRelativePath) || $storedRelativePath === '') {
            throw new \RuntimeException('保存单据图片失败');
        }

        return [
            'file_path' => 'storage/'.$storedRelativePath,
            'original_name' => (string) $file->getClientOriginalName(),
        ];
    }

    private function selectedCollectionId(Request $request): ?int
    {
        if (! $request->has('collection_id')) {
            return AdminWeb::defaultCollectionId();
        }

        return $this->normalizeNullableId($request->query('collection_id', 0));
    }

    /**
     * @return list<array{id:int,label:string,meta:string,url:string,collection_id:int}>
     */
    private function imageOptions(?int $collectionId): array
    {
        return Image::query()
            ->with('library.collection')
            ->when($collectionId !== null && $collectionId > 0, static function ($query) use ($collectionId): void {
                $query->whereHas('library', static fn ($libraryQuery) => $libraryQuery->where('collection_id', $collectionId));
            })
            ->orderByDesc('created_at')
            ->limit(400)
            ->get()
            ->map(static fn (Image $image): array => [
                'id' => (int) $image->id,
                'label' => (string) ($image->original_name ?: $image->filename),
                'meta' => trim((string) ($image->library?->name ?? '').' '.(string) ($image->library?->collection?->name ?? '')),
                'url' => ImageUrlNormalizer::toPublicUrl((string) ($image->file_path ?? '')),
                'collection_id' => (int) ($image->library?->collection_id ?? 0),
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,label:string,meta:string,collection_id:int}>
     */
    private function customerOptions(?int $collectionId): array
    {
        return CrmCustomer::query()
            ->with('collection')
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->orderBy('company_name')
            ->limit(300)
            ->get()
            ->map(static fn (CrmCustomer $customer): array => [
                'id' => (int) $customer->id,
                'label' => trim((string) ($customer->contact_person ?? '')) !== '' ? (string) $customer->contact_person : (string) $customer->company_name,
                'meta' => (string) ($customer->collection?->name ?? ''),
                'collection_id' => (int) ($customer->collection_id ?? 0),
            ])
            ->all();
    }

    /**
     * @return array<int, array{name:string,contact_person:string,phone:string,email:string,tax_number:string,country:string,address:string}>
     */
    private function customerProfiles(?int $collectionId): array
    {
        return CrmCustomer::query()
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->orderBy('company_name')
            ->limit(300)
            ->get(['id', 'company_name', 'contact_person', 'phone', 'email', 'tax_number', 'country', 'address'])
            ->mapWithKeys(static fn (CrmCustomer $customer): array => [
                (int) $customer->id => [
                    'name' => (string) $customer->company_name,
                    'contact_person' => (string) ($customer->contact_person ?? ''),
                    'phone' => (string) ($customer->phone ?? ''),
                    'email' => (string) ($customer->email ?? ''),
                    'tax_number' => (string) ($customer->tax_number ?? ''),
                    'country' => (string) ($customer->country ?? ''),
                    'address' => (string) ($customer->address ?? ''),
                ],
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,label:string,meta:string,collection_id:int}>
     */
    private function inquiryOptions(?int $collectionId, ?int $customerId): array
    {
        return CrmInquiry::query()
            ->with('collection')
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->when($customerId !== null && $customerId > 0, static fn ($query) => $query->where('customer_id', $customerId))
            ->orderByDesc('created_at')
            ->limit(300)
            ->get()
            ->map(static fn (CrmInquiry $inquiry): array => [
                'id' => (int) $inquiry->id,
                'label' => (string) $inquiry->subject,
                'meta' => (string) ($inquiry->collection?->name ?? ''),
                'collection_id' => (int) ($inquiry->collection_id ?? 0),
                'customer_id' => (int) ($inquiry->customer_id ?? 0),
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,label:string,meta:string,collection_id:int}>
     */
    private function opportunityOptions(?int $collectionId, ?int $customerId): array
    {
        return CrmOpportunity::query()
            ->with(['collection', 'customer', 'sourceInquiry'])
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->when($customerId !== null && $customerId > 0, static fn ($query) => $query->where('customer_id', $customerId))
            ->orderByDesc('updated_at')
            ->limit(300)
            ->get()
            ->map(static fn (CrmOpportunity $opportunity): array => [
                'id' => (int) $opportunity->id,
                'label' => (string) $opportunity->name,
                'meta' => trim((string) ($opportunity->customer?->company_name ?? '').' '.(string) ($opportunity->collection?->name ?? '')),
                'collection_id' => (int) ($opportunity->collection_id ?? 0),
                'customer_id' => (int) ($opportunity->customer_id ?? 0),
                'source_inquiry_id' => (int) ($opportunity->source_inquiry_id ?? 0),
            ])
            ->all();
    }

    /**
     * @return list<array{id:int,label:string,meta:string,collection_id:int}>
     */
    private function entityOptions(?int $collectionId): array
    {
        return EntityRecord::query()
            ->with('collection')
            ->when($collectionId !== null && $collectionId > 0, static fn ($query) => $query->where('collection_id', $collectionId))
            ->orderBy('name')
            ->limit(500)
            ->get()
            ->map(static fn (EntityRecord $entity): array => [
                'id' => (int) $entity->id,
                'label' => (string) $entity->name,
                'meta' => trim((string) ($entity->entity_type ?? '').' '.(string) ($entity->collection?->name ?? '')),
                'collection_id' => (int) ($entity->collection_id ?? 0),
            ])
            ->all();
    }

    /**
     * @return array{name:string,logo:string,address:string,phone:string,email:string,website:string}
     */
    public function downloadExcel(int $quoteId, Request $request)
    {
        $quote = CrmQuote::query()
            ->with(['collection', 'customer', 'inquiry.customer.followUps.inquiry', 'opportunity', 'items.entity', 'items.image'])
            ->whereKey($quoteId)
            ->firstOrFail();

        $documentType = $this->resolvedDocumentType($request, $quote);
        $documentLanguage = $this->resolvedDocumentLanguage($request, $quote);
        $seller = $this->sellerProfile($quote);
        $labels = CrmDocumentLocale::labels($documentLanguage);
        $label = static fn (string $key, string $fallback): string => (string) ($labels[$key] ?? $fallback);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', $seller['name'] ?? '');
        $sheet->mergeCells('A1:D1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $titles = CrmDocumentLocale::documentTitles($documentLanguage);
        $title = $titles[$documentType] ?? $titles['quotation'];

        $sheet->setCellValue('A2', $title);
        $sheet->mergeCells('A2:D2');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);

        $row = 4;
        $sheet->setCellValue("A{$row}", $label('document_no', 'Document No.').': '.$quote->quote_no);
        $sheet->setCellValue("C{$row}", $label('date', 'Date').': '.CrmDocumentLocale::formatDate($quote->created_at, $documentLanguage));
        $row++;

        $sheet->setCellValue("A{$row}", $label('buyer', 'Buyer').': '.($quote->buyer_company ?: $quote->customer?->company_name ?: $quote->customer?->name ?? ''));
        $sheet->setCellValue("C{$row}", $label('currency', 'Currency').': '.($quote->currency ?? 'USD'));
        $row++;

        if ($quote->buyer_address) {
            $sheet->setCellValue("A{$row}", $label('address', 'Address').': '.$quote->buyer_address);
            $row++;
        }

        $row++;
        $showPrices = $documentType !== 'packing_list';
        $showLogistics = $documentType === 'packing_list' || $documentType === 'invoice';

        $headers = ['#', $label('item', 'Item'), $label('model', 'Model'), $label('description', 'Description')];
        if ($showPrices) {
            $headers[] = $label('qty', 'Qty');
            $headers[] = $label('unit', 'Unit');
            $headers[] = $label('unit_price', 'Unit Price');
            $headers[] = $label('amount', 'Amount');
        }
        if ($showLogistics) {
            $headers[] = $label('package_count', 'Packages');
            $headers[] = $label('net_weight_short', 'Net Weight (kg)');
            $headers[] = $label('gross_weight_short', 'Gross Weight (kg)');
            $headers[] = $label('cbm', 'CBM');
            $headers[] = $label('package_size_cm', 'Package Size (cm)');
        }
        if ($documentType === 'invoice') {
            $headers[] = $label('hs_code', 'HS Code');
        }

        $headerStyle = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '1F2933']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E5E7EB']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue("{$col}{$row}", $header);
            $col++;
        }

        $lastCol = chr(ord('A') + count($headers) - 1);
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray($headerStyle);
        $row++;
        $dataStartRow = $row;

        $idx = 1;
        foreach ($quote->items as $item) {
            $c = 'A';
            $sheet->setCellValue("{$c}{$row}", $idx++);
            $c++;
            $sheet->setCellValue("{$c}{$row}", $item->item_name ?? '');
            $c++;
            $sheet->setCellValue("{$c}{$row}", $item->model ?? '');
            $c++;
            $sheet->setCellValue("{$c}{$row}", $item->description ?? '');
            $c++;

            if ($showPrices) {
                $sheet->setCellValue("{$c}{$row}", (float) ($item->quantity ?? 0));
                $c++;
                $sheet->setCellValue("{$c}{$row}", $item->unit ?? '');
                $c++;
                $qty = (float) ($item->quantity ?? 0);
                $price = (float) ($item->unit_price ?? 0);
                $sheet->setCellValue("{$c}{$row}", $price);
                $sheet->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
                $c++;
                $sheet->setCellValue("{$c}{$row}", $qty * $price);
                $sheet->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
                $c++;
            }

            if ($showLogistics) {
                $sheet->setCellValue("{$c}{$row}", (int) ($item->package_count ?? 0));
                $c++;
                $sheet->setCellValue("{$c}{$row}", (float) ($item->net_weight ?? 0));
                $sheet->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('#,##0.000');
                $c++;
                $sheet->setCellValue("{$c}{$row}", (float) ($item->gross_weight ?? 0));
                $sheet->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('#,##0.000');
                $c++;
                $sheet->setCellValue("{$c}{$row}", (float) ($item->volume_cbm ?? 0));
                $sheet->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('#,##0.000');
                $c++;
                $dim = '';
                if ($item->package_length || $item->package_width || $item->package_height) {
                    $dim = ($item->package_length ?? '-').'x'.($item->package_width ?? '-').'x'.($item->package_height ?? '-').' cm';
                }
                $sheet->setCellValue("{$c}{$row}", $dim);
                $c++;
            }

            if ($documentType === 'invoice') {
                $sheet->setCellValue("{$c}{$row}", $item->hs_code ?? '');
                $c++;
            }

            $row++;
        }

        $dataEndRow = $row - 1;
        if ($dataEndRow >= $dataStartRow) {
            $sheet->getStyle("A{$dataStartRow}:{$lastCol}{$dataEndRow}")
                ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheet->getStyle("A{$dataStartRow}:A{$dataEndRow}")
                ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        if ($showPrices) {
            $row++;
            $totalCol = chr(ord('A') + array_search($label('amount', 'Amount'), $headers, true));
            $subtotal = $quote->items->sum(fn ($item) => (float) ($item->quantity ?? 0) * (float) ($item->unit_price ?? 0));

            $sheet->setCellValue("A{$row}", $label('subtotal', 'Items Subtotal'));
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->setCellValue("{$totalCol}{$row}", $subtotal);
            $sheet->getStyle("{$totalCol}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("{$totalCol}{$row}")->getFont()->setBold(true);
            $row++;

            if ((float) ($quote->shipping_fee ?? 0) > 0) {
                $sheet->setCellValue("A{$row}", $label('shipping', 'Shipping Fee'));
                $sheet->setCellValue("{$totalCol}{$row}", (float) $quote->shipping_fee);
                $sheet->getStyle("{$totalCol}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
                $row++;
            }

            if ((float) ($quote->discount_amount ?? 0) > 0) {
                $sheet->setCellValue("A{$row}", $label('discount', 'Discount'));
                $sheet->setCellValue("{$totalCol}{$row}", (float) $quote->discount_amount);
                $sheet->getStyle("{$totalCol}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
                $row++;
            }

            $sheet->setCellValue("A{$row}", $label('grand_total', 'Grand Total'));
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
            $total = (float) ($quote->grand_total ?: $quote->total_amount ?: $subtotal);
            $sheet->setCellValue("{$totalCol}{$row}", $total);
            $sheet->getStyle("{$totalCol}{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("{$totalCol}{$row}")->getFont()->setBold(true)->setSize(11);
            $sheet->getStyle("A{$row}:{$totalCol}{$row}")
                ->getBorders()->getTop()->setBorderStyle(Border::BORDER_MEDIUM);
        }

        foreach (range('A', $lastCol) as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        $filename = ($quote->quote_no ?: 'quote').' - '.$documentType.'.xlsx';

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $excelData = ob_get_clean();

        return response($excelData, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function sellerProfile(CrmQuote $quote): array
    {
        $settings = SiteSettingsBag::all();
        $stored = is_array($quote->seller_company_json) ? $quote->seller_company_json : [];

        return [
            'name' => trim((string) ($stored['name'] ?? $settings['site_name'] ?? config('geoflow.site_name', 'GEOFlow'))),
            'logo' => trim((string) ($stored['logo'] ?? $settings['site_logo'] ?? '')),
            'address' => trim((string) ($stored['address'] ?? '')),
            'phone' => trim((string) ($stored['phone'] ?? '')),
            'email' => trim((string) ($stored['email'] ?? '')),
            'website' => trim((string) ($stored['website'] ?? config('app.url', ''))),
        ];
    }
}
