<?php

declare(strict_types=1);

return [
    'zh-CN' => [
        'meta' => [
            'badge' => '使用说明',
            'switch_label' => 'English',
            'close' => '关闭',
            'links_label' => '本项目基于 GEOFlow 定制开发，原仓库见下方链接。',
            'github_link' => '项目仓库',
            'original_repo_link' => '原仓库地址',
        ],
        'letter' => [
            'title' => 'GEOFlow 外贸 CRM 系统',
            'subtitle' => '基于 GEOFlow 2.0 定制的轻量级外贸 CRM + 单据管理系统。使用前请先完成前置数据准备。',
            'blocks' => [
                [
                    'type' => 'heading',
                    'content' => '前置条件：素材与知识库准备',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '创建 Collection（业务容器），所有素材和 CRM 数据将归属到指定容器下',
                        '在 Collection 下建立 Entity（产品/设备实体），填写名称、型号、属性等基本信息',
                        '上传 Knowledge Base（知识库资料），包括技术文档、产品手册、FAQ 等内容',
                        '创建 Case（应用案例），记录典型客户场景和解决方案',
                        '配置 AI 模型和提示词，确保内容分析和生成功能可用',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'CRM 业务流程',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '客户管理：录入客户基本信息（公司名、联系人、电话、邮箱、地址、国家）',
                        '询盘管理：记录客户询盘内容，AI 自动识别需求、关联 Entity 和知识库',
                        '单据制作：从询盘生成报价单 / 形式发票 / 正式发票 / 装箱单 / 合同，填写明细和条款',
                        '订单管理：从报价生成销售订单，跟踪生产、付款、交付状态',
                        '售后工单：处理客户售后问题，关联订单和 Entity，AI 辅助分析',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => '单据打印与跟进',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '支持 5 种单据类型：Quotation / Proforma Invoice / Commercial Invoice / Packing List / Contract',
                        '打印预览使用浏览器 Ctrl+P 打印为 PDF，A4 格式自动适配',
                        '跟进记录可在客户、询盘、报价、订单、售后页面多端查看和添加',
                        '跟进编辑器支持 Markdown 格式（编辑 / 预览 / 源码三态切换）',
                        '买方信息支持从客户资料一键带入，卖方信息通过 JSON 配置灵活调整',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => '快速上手步骤',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '1. 创建 Collection → 2. 录入 Entity 和 Knowledge Base → 3. 添加客户',
                        '4. 创建询盘并 AI 分析 → 5. 生成报价单 → 6. 打印单据预览',
                        '7. 报价确认后生成订单 → 8. 订单交付后创建售后工单（如有需要）',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => '本系统基于开源项目 GEOFlow（yaojingang/GEOFlow）二次开发，感谢原作者的贡献。',
                ],
            ],
        ],
    ],
    'en' => [
        'meta' => [
            'badge' => 'User Guide',
            'switch_label' => '中文',
            'close' => 'Close',
            'links_label' => 'This project is based on GEOFlow. See the original repository below.',
            'github_link' => 'Project Repo',
            'original_repo_link' => 'Original Repo',
        ],
        'letter' => [
            'title' => 'GEOFlow Foreign Trade CRM',
            'subtitle' => 'A lightweight foreign trade CRM + document management system built on GEOFlow 2.0. Complete the prerequisites first.',
            'blocks' => [
                [
                    'type' => 'heading',
                    'content' => 'Prerequisites: Materials and Knowledge Base',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'Create a Collection (business container) — all materials and CRM data belong to it',
                        'Create Entities (product/equipment) under the Collection with name, model, and attributes',
                        'Upload Knowledge Base items: technical docs, product manuals, FAQs',
                        'Create Cases to document typical customer scenarios and solutions',
                        'Configure AI models and prompts for content analysis and generation',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'CRM Workflow',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        'Customer management: company name, contact person, phone, email, address, country',
                        'Inquiry management: record customer inquiries with AI-powered need recognition',
                        'Document creation: generate Quotation / Proforma Invoice / Commercial Invoice / Packing List / Contract',
                        'Order management: convert quotes to sales orders and track production, payment, delivery',
                        'After-sales tickets: handle post-sales issues linked to orders and entities',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'Document Printing and Follow-ups',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '5 document types: Quotation / Proforma Invoice / Commercial Invoice / Packing List / Contract',
                        'Print preview via browser Ctrl+P to save as A4 PDF',
                        'Follow-up records visible across customer, inquiry, quote, order, and ticket detail pages',
                        'Markdown editor with write / preview / source code modes',
                        'Buyer info auto-fill from customer profile; seller info configurable via JSON',
                    ],
                ],
                [
                    'type' => 'heading',
                    'content' => 'Quick Start',
                ],
                [
                    'type' => 'list',
                    'items' => [
                        '1. Create Collection → 2. Add Entities & Knowledge Base → 3. Add Customers',
                        '4. Create Inquiry & AI analysis → 5. Generate Quotation → 6. Print preview',
                        '7. Convert to Order → 8. Create After-sales Ticket if needed',
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => 'Built on the open-source GEOFlow project (yaojingang/GEOFlow). Thanks to the original author.',
                ],
            ],
        ],
    ],
];
