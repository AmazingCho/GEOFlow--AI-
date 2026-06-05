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
                    if (field.tagName === 'SELECT' && field.multiple && Array.isArray(value)) {
                        const selected = new Set(value.map((item) => String(item)));
                        Array.from(field.options).forEach((option) => {
                            option.selected = selected.has(String(option.value));
                        });
                        field.dispatchEvent(new Event('change', {bubbles: true}));
                        return;
                    }
                    if (field.tagName === 'SELECT') {
                        const stringValue = String(typeof value === 'object' ? '' : value);
                        const hasOption = Array.from(field.options).some((option) => option.value === stringValue);
                        field.value = hasOption ? stringValue : (field.querySelector('option[value="业务实体"]') ? '业务实体' : field.value);
                        field.dispatchEvent(new Event('input', {bubbles: true}));
                        field.dispatchEvent(new Event('change', {bubbles: true}));
                        return;
                    }
                    field.value = typeof value === 'object' ? JSON.stringify(value, null, 2) : String(value);
                    field.dispatchEvent(new Event('input', {bubbles: true}));
                    field.dispatchEvent(new Event('change', {bubbles: true}));
                }

                function selectOptionIds(form, fieldName, ids) {
                    if (!Array.isArray(ids)) {
                        return;
                    }
                    const selector = form.querySelector(`[data-option-multi-selector][data-field-name="${fieldName}"]`);
                    if (!selector) {
                        return;
                    }
                    ids.map((id) => String(id)).filter(Boolean).forEach((id) => {
                        const hasSelected = selector.querySelector(`[data-option-chip][data-option-id="${CSS.escape(id)}"]`);
                        if (hasSelected) {
                            return;
                        }
                        selector.querySelector(`[data-option-item][data-option-id="${CSS.escape(id)}"]`)?.click();
                    });
                }

                document.addEventListener('click', (event) => {
                    const templateButton = event.target.closest('[data-ai-analysis-template]');
                    if (templateButton) {
                        const form = templateButton.closest('[data-ai-analysis-form]');
                        const instructions = form?.querySelector('[data-ai-analysis-instructions]');
                        const template = templateButton.dataset.aiAnalysisTemplate || '';
                        if (instructions && template !== '') {
                            const current = instructions.value.trim();
                            instructions.value = current === '' ? template : `${current}\n${template}`;
                            instructions.dispatchEvent(new Event('input', {bubbles: true}));
                            instructions.focus();
                        }
                        return;
                    }

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
                            title: form.querySelector('[name="name"]')?.value || form.querySelector('[name="title"]')?.value || '',
                            source_url: form.querySelector('[name="source_url"]')?.value || '',
                            ai_model_id: form.querySelector('[data-ai-analysis-model]')?.value || 0,
                            analysis_instructions: form.querySelector('[data-ai-analysis-instructions]')?.value || '',
                        }),
                    })
                        .then((response) => response.ok ? response.json() : Promise.reject(response))
                        .then((payload) => {
                            const fields = payload.fields || {};
                            Object.keys(fields).forEach((name) => {
                                if (name === 'entity_ids') {
                                    selectOptionIds(form, 'entity_ids', fields[name]);
                                } else if (name !== 'tags') {
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
