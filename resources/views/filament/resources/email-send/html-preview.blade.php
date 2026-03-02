<div class="rounded-lg border border-gray-200 dark:border-gray-700">
    <div class="bg-gray-50 dark:bg-gray-800 px-4 py-2 border-b border-gray-200 dark:border-gray-700">
        <p class="text-xs text-gray-600 dark:text-gray-400">
            HTML Email Preview (sanitized for security)
        </p>
    </div>
    <div class="bg-white dark:bg-gray-900 p-4 max-h-96 overflow-y-auto">
        <iframe
            srcdoc="{{ htmlspecialchars($getState() ?? '', ENT_QUOTES) }}"
            class="w-full border-0"
            style="min-height: 400px;"
            sandbox="allow-same-origin"
        ></iframe>
    </div>
</div>
