@once
    @push('scripts')
        <script>
            (() => {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

                function fillField(form, name, value) {
                    if (value === undefined || value === null) {
                        return;
                    }
                    const field = form.querySelector(`[name="${name}"]`);
                    if (!field) {
                        return;
                    }
                    field.value = typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value);
                    field.dispatchEvent(new Event('input', {bubbles: true}));
                    field.dispatchEvent(new Event('change', {bubbles: true}));
                }

                document.addEventListener('click', (event) => {
                    const button = event.target.closest('[data-ai-analysis-submit]');
                    if (!button) {
                        return;
                    }
                    const form = button.closest('[data-ai-analysis-form]');
                    const status = form?.querySelector('[data-ai-analysis-status]');
                    const content = form?.querySelector('[data-ai-analysis-content]')?.value || '';
                    if (!form || content.trim() === '') {
                        if (status) {
                            status.textContent = '请先输入待分析内容。';
                            status.classList.remove('hidden');
                        }
                        return;
                    }

                    button.disabled = true;
                    if (status) {
                        status.textContent = '正在分析...';
                        status.classList.remove('hidden');
                    }

                    fetch(form.dataset.aiAnalysisUrl || '', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({
                            content,
                            ai_model_id: form.querySelector('[data-ai-analysis-model]')?.value || 0,
                        }),
                    })
                        .then((response) => response.ok ? response.json() : Promise.reject(response))
                        .then((payload) => {
                            const fields = payload.fields || {};
                            Object.keys(fields).forEach((name) => {
                                if (name !== 'tags') {
                                    fillField(form, name, fields[name]);
                                }
                            });
	                            const tagText = form.querySelector('[data-ai-analysis-tags]');
	                            if (tagText && Array.isArray(fields.tags) && fields.tags.length > 0) {
	                                tagText.textContent = 'AI 标签建议：' + fields.tags.join('、') + '。正在匹配已有标签...';
	                                tagText.classList.remove('hidden');
	                                const selector = form.querySelector('[data-tag-selector]');
	                                if (selector && window.GeoFlowTagSelector?.selectLabels) {
	                                    window.GeoFlowTagSelector.selectLabels(selector, fields.tags).then((matched) => {
	                                        const matchedLabels = (matched || []).map((tag) => tag.label).filter(Boolean);
	                                        tagText.textContent = matchedLabels.length > 0
	                                            ? '已自动选中已有标签：' + matchedLabels.join('、') + '。'
	                                            : 'AI 标签建议：' + fields.tags.join('、') + '。未找到完全匹配的已有标签，请在标签管理页创建后再选择。';
	                                    });
	                                } else {
	                                    tagText.textContent = 'AI 标签建议：' + fields.tags.join('、') + '。可在下方搜索已有标签后选择。';
	                                }
	                            }
                            if (status) {
                                status.textContent = '分析完成，已填入可识别字段。';
                            }
                        })
                        .catch(() => {
                            if (status) {
                                status.textContent = '分析失败，请检查模型配置后重试。';
                            }
                        })
                        .finally(() => {
                            button.disabled = false;
                        });
                });
            })();
        </script>
    @endpush
@endonce
