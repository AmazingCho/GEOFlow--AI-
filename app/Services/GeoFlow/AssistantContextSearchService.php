<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ApiException;
use App\Models\CaseRecord;
use App\Models\CollectionRecord;
use App\Models\CrmAfterSalesTicket;
use App\Models\CrmCustomer;
use App\Models\CrmCustomerContact;
use App\Models\CrmInquiry;
use App\Models\CrmOpportunity;
use App\Models\CrmQuote;
use App\Models\CrmSalesOrder;
use App\Models\EntityRecord;
use App\Models\KnowledgeBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AssistantContextSearchService
{
    private const DEFAULT_LIMIT = 5;

    private const MAX_LIMIT = 20;

    /**
     * @return array<string, mixed>
     */
    public function search(?string $query, ?int $collectionId = null, ?int $limit = null): array
    {
        $query = mb_substr(trim((string) $query), 0, 200);
        $collectionId = $collectionId !== null && $collectionId > 0 ? $collectionId : null;
        $limit = $this->normalizeLimit($limit);

        if ($query === '' && $collectionId === null) {
            throw new ApiException('validation_failed', '请提供搜索关键词或 collection_id', 422, [
                'field_errors' => ['q' => '请提供搜索关键词或 collection_id'],
            ]);
        }

        $collection = null;
        if ($collectionId !== null) {
            $collection = CollectionRecord::query()->whereKey($collectionId)->first();
            if (! $collection) {
                throw new ApiException('collection_not_found', 'Collection 不存在', 404);
            }
        }

        return [
            'query' => $query,
            'collection' => $collection ? $this->collectionSummary($collection) : null,
            'limit' => $limit,
            'sections' => [
                'customers' => $this->customers($query, $collectionId, $limit),
                'contacts' => $this->contacts($query, $collectionId, $limit),
                'inquiries' => $this->inquiries($query, $collectionId, $limit),
                'opportunities' => $this->opportunities($query, $collectionId, $limit),
                'quotes' => $this->quotes($query, $collectionId, $limit),
                'orders' => $this->orders($query, $collectionId, $limit),
                'tickets' => $this->tickets($query, $collectionId, $limit),
                'entities' => $this->entities($query, $collectionId, $limit),
                'knowledge_bases' => $this->knowledgeBases($query, $collectionId, $limit),
                'cases' => $this->cases($query, $collectionId, $limit),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customers(string $query, ?int $collectionId, int $limit): array
    {
        return CrmCustomer::query()
            ->with('collection:id,name,slug')
            ->when($collectionId, static fn (Builder $q): Builder => $q->where('collection_id', $collectionId))
            ->when($query !== '', function (Builder $q) use ($query): void {
                $q->where(function (Builder $search) use ($query): void {
                    $this->whereLikeAny($search, ['company_name', 'contact_person', 'country', 'industry', 'source_channel', 'phone', 'email', 'tax_number', 'notes'], $query);
                    $search->orWhereHas('inquiries', function (Builder $inquiry) use ($query): void {
                        $this->whereLikeAny($inquiry, ['subject', 'raw_message', 'customer_need_summary', 'product_interest'], $query);
                    });
                    $search->orWhereHas('opportunities', function (Builder $opportunity) use ($query): void {
                        $this->whereLikeAny($opportunity, ['name', 'stage', 'next_step', 'notes'], $query);
                    });
                    $search->orWhereHas('quotes', function (Builder $quote) use ($query): void {
                        $this->whereLikeAny($quote, ['quote_no', 'title', 'buyer_company', 'notes'], $query);
                    });
                    $search->orWhereHas('salesOrders', function (Builder $order) use ($query): void {
                        $this->whereLikeAny($order, ['order_no', 'title', 'order_status', 'notes'], $query);
                    });
                    $search->orWhereHas('afterSalesTickets', function (Builder $ticket) use ($query): void {
                        $this->whereLikeAny($ticket, ['title', 'issue_description', 'issue_type', 'resolution'], $query);
                    });
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CrmCustomer $customer): array => [
                'id' => (int) $customer->id,
                'label' => (string) $customer->company_name,
                'type' => 'customer',
                'collection' => $this->optionalCollectionSummary($customer->collection),
                'summary' => trim(implode(' · ', array_filter([
                    (string) ($customer->contact_person ?? ''),
                    (string) ($customer->country ?? ''),
                    (string) ($customer->owner ?? ''),
                ]))),
                'fields' => [
                    'company_name' => (string) $customer->company_name,
                    'contact_person' => (string) ($customer->contact_person ?? ''),
                    'email' => (string) ($customer->email ?? ''),
                    'phone' => (string) ($customer->phone ?? ''),
                    'status' => (string) ($customer->status ?? ''),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contacts(string $query, ?int $collectionId, int $limit): array
    {
        return CrmCustomerContact::query()
            ->with('customer.collection:id,name,slug')
            ->when($collectionId, static fn (Builder $q): Builder => $q->whereHas('customer', static fn (Builder $customer): Builder => $customer->where('collection_id', $collectionId)))
            ->when($query !== '', function (Builder $q) use ($query): void {
                $q->where(function (Builder $search) use ($query): void {
                    $this->whereLikeAny($search, ['name', 'title', 'department', 'phone', 'email', 'decision_role', 'notes'], $query);
                    $search->orWhereHas('customer', function (Builder $customer) use ($query): void {
                        $this->whereLikeAny($customer, ['company_name', 'contact_person', 'country', 'email', 'phone'], $query);
                    });
                });
            })
            ->orderByDesc('is_primary')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (CrmCustomerContact $contact): array => [
                'id' => (int) $contact->id,
                'label' => (string) $contact->name,
                'type' => 'contact',
                'collection' => $this->optionalCollectionSummary($contact->customer?->collection),
                'summary' => trim(implode(' · ', array_filter([
                    (string) ($contact->customer?->company_name ?? ''),
                    (string) ($contact->title ?? ''),
                    (string) ($contact->email ?? ''),
                ]))),
                'fields' => [
                    'customer_id' => $this->nullableInt($contact->customer_id),
                    'customer_name' => (string) ($contact->customer?->company_name ?? ''),
                    'email' => (string) ($contact->email ?? ''),
                    'phone' => (string) ($contact->phone ?? ''),
                    'is_primary' => (bool) $contact->is_primary,
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inquiries(string $query, ?int $collectionId, int $limit): array
    {
        return CrmInquiry::query()
            ->with(['collection:id,name,slug', 'customer:id,company_name'])
            ->when($collectionId, static fn (Builder $q): Builder => $q->where('collection_id', $collectionId))
            ->when($query !== '', function (Builder $q) use ($query): void {
                $q->where(function (Builder $search) use ($query): void {
                    $this->whereLikeAny($search, ['subject', 'raw_message', 'customer_need_summary', 'product_interest', 'suggested_reply_points', 'notes'], $query);
                    $search->orWhereHas('customer', function (Builder $customer) use ($query): void {
                        $this->whereLikeAny($customer, ['company_name', 'contact_person', 'country', 'email', 'phone'], $query);
                    });
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CrmInquiry $inquiry): array => [
                'id' => (int) $inquiry->id,
                'label' => (string) $inquiry->subject,
                'type' => 'inquiry',
                'collection' => $this->optionalCollectionSummary($inquiry->collection),
                'summary' => $this->excerpt((string) ($inquiry->customer_need_summary ?: $inquiry->raw_message)),
                'fields' => [
                    'customer_id' => $this->nullableInt($inquiry->customer_id),
                    'customer_name' => (string) ($inquiry->customer?->company_name ?? ''),
                    'status' => (string) ($inquiry->status ?? ''),
                    'priority' => (string) ($inquiry->priority ?? ''),
                    'urgency_level' => (string) ($inquiry->urgency_level ?? ''),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function opportunities(string $query, ?int $collectionId, int $limit): array
    {
        return CrmOpportunity::query()
            ->with(['collection:id,name,slug', 'customer:id,company_name', 'sourceInquiry:id,subject'])
            ->when($collectionId, static fn (Builder $q): Builder => $q->where('collection_id', $collectionId))
            ->when($query !== '', function (Builder $q) use ($query): void {
                $q->where(function (Builder $search) use ($query): void {
                    $this->whereLikeAny($search, ['name', 'stage', 'competitor', 'next_step', 'notes'], $query);
                    $search->orWhereHas('customer', function (Builder $customer) use ($query): void {
                        $this->whereLikeAny($customer, ['company_name', 'contact_person', 'country'], $query);
                    });
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CrmOpportunity $opportunity): array => [
                'id' => (int) $opportunity->id,
                'label' => (string) $opportunity->name,
                'type' => 'opportunity',
                'collection' => $this->optionalCollectionSummary($opportunity->collection),
                'summary' => trim(implode(' · ', array_filter([
                    (string) ($opportunity->customer?->company_name ?? ''),
                    (string) ($opportunity->stage ?? ''),
                    (string) ($opportunity->next_step ?? ''),
                ]))),
                'fields' => [
                    'customer_id' => $this->nullableInt($opportunity->customer_id),
                    'customer_name' => (string) ($opportunity->customer?->company_name ?? ''),
                    'source_inquiry_id' => $this->nullableInt($opportunity->source_inquiry_id),
                    'source_inquiry_subject' => (string) ($opportunity->sourceInquiry?->subject ?? ''),
                    'stage' => (string) ($opportunity->stage ?? ''),
                    'amount' => $opportunity->amount !== null ? (string) $opportunity->amount : null,
                    'currency' => (string) ($opportunity->currency ?? ''),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quotes(string $query, ?int $collectionId, int $limit): array
    {
        return CrmQuote::query()
            ->with(['collection:id,name,slug', 'customer:id,company_name'])
            ->when($collectionId, static fn (Builder $q): Builder => $q->where('collection_id', $collectionId))
            ->when($query !== '', function (Builder $q) use ($query): void {
                $q->where(function (Builder $search) use ($query): void {
                    $this->whereLikeAny($search, ['quote_no', 'title', 'buyer_company', 'buyer_contact', 'buyer_email', 'notes', 'internal_notes'], $query);
                    $search->orWhereHas('customer', function (Builder $customer) use ($query): void {
                        $this->whereLikeAny($customer, ['company_name', 'contact_person'], $query);
                    });
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CrmQuote $quote): array => [
                'id' => (int) $quote->id,
                'label' => (string) ($quote->quote_no ?: $quote->title),
                'type' => 'quote',
                'collection' => $this->optionalCollectionSummary($quote->collection),
                'summary' => trim(implode(' · ', array_filter([
                    (string) ($quote->title ?? ''),
                    (string) ($quote->customer?->company_name ?? ''),
                    (string) ($quote->status ?? ''),
                ]))),
                'fields' => [
                    'customer_id' => $this->nullableInt($quote->customer_id),
                    'customer_name' => (string) ($quote->customer?->company_name ?? ''),
                    'document_type' => (string) ($quote->document_type ?? ''),
                    'status' => (string) ($quote->status ?? ''),
                    'grand_total' => $quote->grand_total !== null ? (string) $quote->grand_total : null,
                    'currency' => (string) ($quote->currency ?? ''),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orders(string $query, ?int $collectionId, int $limit): array
    {
        return CrmSalesOrder::query()
            ->with(['collection:id,name,slug', 'customer:id,company_name'])
            ->when($collectionId, static fn (Builder $q): Builder => $q->where('collection_id', $collectionId))
            ->when($query !== '', function (Builder $q) use ($query): void {
                $q->where(function (Builder $search) use ($query): void {
                    $this->whereLikeAny($search, ['order_no', 'title', 'payment_status', 'production_status', 'delivery_status', 'order_status', 'notes'], $query);
                    $search->orWhereHas('customer', function (Builder $customer) use ($query): void {
                        $this->whereLikeAny($customer, ['company_name', 'contact_person'], $query);
                    });
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CrmSalesOrder $order): array => [
                'id' => (int) $order->id,
                'label' => (string) ($order->order_no ?: $order->title),
                'type' => 'order',
                'collection' => $this->optionalCollectionSummary($order->collection),
                'summary' => trim(implode(' · ', array_filter([
                    (string) ($order->title ?? ''),
                    (string) ($order->customer?->company_name ?? ''),
                    (string) ($order->order_status ?? ''),
                ]))),
                'fields' => [
                    'customer_id' => $this->nullableInt($order->customer_id),
                    'customer_name' => (string) ($order->customer?->company_name ?? ''),
                    'order_status' => (string) ($order->order_status ?? ''),
                    'payment_status' => (string) ($order->payment_status ?? ''),
                    'total_amount' => $order->total_amount !== null ? (string) $order->total_amount : null,
                    'currency' => (string) ($order->currency ?? ''),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tickets(string $query, ?int $collectionId, int $limit): array
    {
        return CrmAfterSalesTicket::query()
            ->with(['collection:id,name,slug', 'customer:id,company_name', 'entity:id,name'])
            ->when($collectionId, static fn (Builder $q): Builder => $q->where('collection_id', $collectionId))
            ->when($query !== '', function (Builder $q) use ($query): void {
                $q->where(function (Builder $search) use ($query): void {
                    $this->whereLikeAny($search, ['title', 'issue_description', 'issue_type', 'reply_points', 'resolution', 'notes'], $query);
                    $search->orWhereHas('customer', function (Builder $customer) use ($query): void {
                        $this->whereLikeAny($customer, ['company_name', 'contact_person'], $query);
                    });
                    $search->orWhereHas('entity', function (Builder $entity) use ($query): void {
                        $this->whereLikeAny($entity, ['name', 'aliases', 'description'], $query);
                    });
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CrmAfterSalesTicket $ticket): array => [
                'id' => (int) $ticket->id,
                'label' => (string) $ticket->title,
                'type' => 'ticket',
                'collection' => $this->optionalCollectionSummary($ticket->collection),
                'summary' => $this->excerpt((string) ($ticket->issue_description ?: $ticket->resolution)),
                'fields' => [
                    'customer_id' => $this->nullableInt($ticket->customer_id),
                    'customer_name' => (string) ($ticket->customer?->company_name ?? ''),
                    'entity_id' => $this->nullableInt($ticket->entity_id),
                    'entity_name' => (string) ($ticket->entity?->name ?? ''),
                    'status' => (string) ($ticket->status ?? ''),
                    'priority' => (string) ($ticket->priority ?? ''),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entities(string $query, ?int $collectionId, int $limit): array
    {
        return EntityRecord::query()
            ->with('collection:id,name,slug')
            ->when($collectionId, static fn (Builder $q): Builder => $q->where('collection_id', $collectionId))
            ->when($query !== '', fn (Builder $q): Builder => $this->whereLikeAny($q, ['name', 'entity_type', 'aliases', 'description', 'attributes_json', 'source_url', 'canonical_url'], $query))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (EntityRecord $entity): array => [
                'id' => (int) $entity->id,
                'label' => (string) $entity->name,
                'type' => 'entity',
                'collection' => $this->optionalCollectionSummary($entity->collection),
                'summary' => $this->excerpt((string) $entity->description),
                'fields' => [
                    'entity_type' => (string) ($entity->entity_type ?? ''),
                    'aliases' => (string) ($entity->aliases ?? ''),
                    'canonical_url' => (string) ($entity->canonical_url ?? ''),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function knowledgeBases(string $query, ?int $collectionId, int $limit): array
    {
        return KnowledgeBase::query()
            ->with('collection:id,name,slug')
            ->when($collectionId, static fn (Builder $q): Builder => $q->where('collection_id', $collectionId))
            ->when($query !== '', fn (Builder $q): Builder => $this->whereLikeAny($q, ['name', 'description', 'summary', 'source_url', 'content', 'knowledge_type', 'knowledge_role'], $query))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (KnowledgeBase $knowledgeBase): array => [
                'id' => (int) $knowledgeBase->id,
                'label' => (string) $knowledgeBase->name,
                'type' => 'knowledge_base',
                'collection' => $this->optionalCollectionSummary($knowledgeBase->collection),
                'summary' => $this->excerpt((string) ($knowledgeBase->summary ?: $knowledgeBase->description)),
                'fields' => [
                    'knowledge_type' => (string) ($knowledgeBase->knowledge_type ?? ''),
                    'knowledge_role' => (string) ($knowledgeBase->knowledge_role ?? ''),
                    'status' => (string) ($knowledgeBase->status ?? ''),
                    'source_url' => (string) ($knowledgeBase->source_url ?? ''),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cases(string $query, ?int $collectionId, int $limit): array
    {
        return CaseRecord::query()
            ->with(['collection:id,name,slug', 'entity:id,name'])
            ->when($collectionId, static fn (Builder $q): Builder => $q->where('collection_id', $collectionId))
            ->when($query !== '', fn (Builder $q): Builder => $this->whereLikeAny($q, ['title', 'case_type', 'summary', 'challenge', 'solution', 'result', 'metrics', 'source_url'], $query))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (CaseRecord $case): array => [
                'id' => (int) $case->id,
                'label' => (string) $case->title,
                'type' => 'case',
                'collection' => $this->optionalCollectionSummary($case->collection),
                'summary' => $this->excerpt((string) ($case->summary ?: $case->result)),
                'fields' => [
                    'entity_id' => $this->nullableInt($case->entity_id),
                    'entity_name' => (string) ($case->entity?->name ?? ''),
                    'case_type' => (string) ($case->case_type ?? ''),
                    'source_url' => (string) ($case->source_url ?? ''),
                ],
            ])
            ->values()
            ->all();
    }

    private function normalizeLimit(?int $limit): int
    {
        $limit = $limit !== null && $limit > 0 ? $limit : self::DEFAULT_LIMIT;

        return min(self::MAX_LIMIT, max(1, $limit));
    }

    /**
     * @param  list<string>  $columns
     */
    private function whereLikeAny(Builder $query, array $columns, string $value): Builder
    {
        $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $value).'%';

        return $query->where(function (Builder $inner) use ($columns, $needle): void {
            foreach ($columns as $column) {
                $inner->orWhere($column, 'like', $needle);
            }
        });
    }

    /**
     * @return array{id:int,name:string,slug:string}
     */
    private function collectionSummary(CollectionRecord $collection): array
    {
        return [
            'id' => (int) $collection->id,
            'name' => (string) $collection->name,
            'slug' => (string) $collection->slug,
        ];
    }

    /**
     * @return array{id:int,name:string,slug:string}|null
     */
    private function optionalCollectionSummary(?Model $collection): ?array
    {
        if (! $collection instanceof CollectionRecord) {
            return null;
        }

        return $this->collectionSummary($collection);
    }

    private function excerpt(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?: '');

        return mb_substr($value, 0, 180);
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }
}
