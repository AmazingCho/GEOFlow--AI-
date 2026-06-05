<details class="mt-3 rounded-lg border border-gray-200 bg-white/80 px-3 py-2">
    <summary class="cursor-pointer text-sm font-semibold text-gray-700">补充分析要求</summary>
    <div class="mt-3 space-y-3">
        <p class="text-xs leading-5 text-gray-500">
            可补充本次分析的重点，例如保留表格数值、指定输出语言、优先识别产品参数或只在存在真实场景时提取案例。系统仍会强制遵守字段结构、事实准确性和表格保真规则。
        </p>
        <textarea
            data-ai-analysis-instructions
            rows="3"
            class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm leading-6 shadow-sm outline-none focus:border-blue-500 focus:ring-blue-500"
            placeholder="例如：请重点整理产品参数表、型号、单位、尺寸、电压、功率、速度、容量等数值，不要合并型号，不要四舍五入。"
        ></textarea>
        <div class="flex flex-wrap gap-2 text-xs">
            <button type="button" data-ai-analysis-template="请重点保留表格中的型号、单位、尺寸、电压、功率、速度、容量、温度、压力等原始数值，不要四舍五入，不要合并相近型号。" class="rounded-full border border-gray-300 bg-white px-2.5 py-1 font-medium text-gray-600 hover:bg-gray-50">表格参数保真</button>
            <button type="button" data-ai-analysis-template="请使用英文输出摘要、描述和结构化字段，品牌名、产品型号、单位和来源 URL 保持原样。" class="rounded-full border border-gray-300 bg-white px-2.5 py-1 font-medium text-gray-600 hover:bg-gray-50">英文输出</button>
            <button type="button" data-ai-analysis-template="只有当文本中存在真实应用场景、客户问题、解决方案或结果指标时，才提取案例；不要从普通产品说明中编造案例。" class="rounded-full border border-gray-300 bg-white px-2.5 py-1 font-medium text-gray-600 hover:bg-gray-50">谨慎提取案例</button>
        </div>
    </div>
</details>
