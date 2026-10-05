import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import hljs from 'highlight.js/lib/core';
import javascript from 'highlight.js/lib/languages/javascript';
import json from 'highlight.js/lib/languages/json';
import php from 'highlight.js/lib/languages/php';
import python from 'highlight.js/lib/languages/python';
import sql from 'highlight.js/lib/languages/sql';
import bash from 'highlight.js/lib/languages/bash';
import plaintext from 'highlight.js/lib/languages/plaintext';
import 'highlight.js/styles/github-dark.css';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';

hljs.registerLanguage('javascript', javascript);
hljs.registerLanguage('json', json);
hljs.registerLanguage('php', php);
hljs.registerLanguage('python', python);
hljs.registerLanguage('sql', sql);
hljs.registerLanguage('bash', bash);
hljs.registerLanguage('plaintext', plaintext);

/**
 * Salin teks ke clipboard. navigator.clipboard hanya ada di https/localhost,
 * jadi sediakan fallback execCommand untuk domain .test via http.
 */
async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    textarea.remove();
}

const isDark = () => document.documentElement.classList.contains('dark');

const swalBase = () => ({
    theme: isDark() ? 'dark' : 'light',
    buttonsStyling: false,
});

/**
 * Notifikasi kecil di pojok kanan bawah (SweetAlert2 toast).
 */
function toast(message, type = 'success') {
    Swal.fire({
        ...swalBase(),
        toast: true,
        position: 'bottom-end',
        icon: type === 'error' ? 'error' : 'success',
        title: message,
        showConfirmButton: false,
        timer: type === 'error' ? 6000 : 3000,
        timerProgressBar: true,
        customClass: { popup: 'swal-toast-app' },
    });
}

/**
 * Dialog konfirmasi. Dipakai di Blade: x-on:click="$confirm('Hapus?', () => $wire.delete(1), { danger: true })"
 */
function confirmDialog(text, onConfirm, options = {}) {
    return Swal.fire({
        ...swalBase(),
        title: options.title ?? 'Yakin?',
        text,
        icon: options.icon ?? (options.danger ? 'warning' : 'question'),
        showCancelButton: true,
        confirmButtonText: options.confirm ?? 'Ya, lanjutkan',
        cancelButtonText: 'Batal',
        reverseButtons: true,
        focusCancel: Boolean(options.danger),
        customClass: {
            popup: 'swal-app',
            confirmButton: options.danger ? 'btn-danger' : 'btn-primary',
            cancelButton: 'btn-secondary',
        },
    }).then((result) => {
        if (result.isConfirmed) {
            onConfirm();
        }
    });
}

// Event "toast" dari Livewire: $this->dispatch('toast', message: '...', type: 'error')
window.addEventListener('toast', (event) => toast(event.detail.message, event.detail.type));

/**
 * Tema: light | dark | system, disimpan di localStorage. Class "dark" sudah dipasang
 * lebih awal oleh script kecil di <head> supaya tidak berkedip.
 */
const themeMedia = window.matchMedia('(prefers-color-scheme: dark)');

function readTheme() {
    try {
        return localStorage.getItem('theme') || 'system';
    } catch (e) {
        return 'system';
    }
}

function applyTheme(mode) {
    const dark = mode === 'dark' || (mode === 'system' && themeMedia.matches);
    document.documentElement.classList.toggle('dark', dark);
}

themeMedia.addEventListener('change', () => applyTheme(readTheme()));

// wire:navigate mengganti atribut <html> dengan milik halaman baru (class "dark" & "sidebar-collapsed"
// ikut hilang), jadi pasang ulang tepat saat halaman ditukar supaya tidak berkedip / melompat.
const applyShell = () => (window.applyShellPrefs ? window.applyShellPrefs() : applyTheme(readTheme()));

document.addEventListener('livewire:navigating', (event) => {
    event.detail?.onSwap?.(applyShell);
});
document.addEventListener('livewire:navigated', applyShell);

document.addEventListener('alpine:init', () => {
    Alpine.store('theme', {
        mode: readTheme(),
        set(mode) {
            this.mode = mode;
            try {
                localStorage.setItem('theme', mode);
            } catch (e) {}
            applyTheme(mode);
        },
    });

    Alpine.magic('confirm', () => confirmDialog);
});

window.copyText = copyText;
window.toast = toast;
window.confirmDialog = confirmDialog;

// Tombol copy generik: <button x-data="copyButton" @click="copy('teks')">
Alpine.data('copyButton', () => ({
    copied: false,
    async copy(text) {
        await copyText(text);
        this.copied = true;
        toast('Disalin ke clipboard');
        setTimeout(() => (this.copied = false), 1500);
    },
}));

// Blok kode dengan syntax highlight + tombol copy
// Kode panjang hanya ditampilkan sebagian (truncated) dan tidak di-highlight bila besar,
// karena highlight ribuan baris sekaligus membuat browser berat.
const HIGHLIGHT_LIMIT = 60_000;

Alpine.data('codeBlock', ({ truncated = false, load = null } = {}) => ({
    copied: false,
    loading: false,
    truncated,
    fullText: null,
    init() {
        this.highlight();
    },
    highlight() {
        const el = this.$refs.code;

        if (el && el.tagName === 'CODE' && el.textContent.length <= HIGHLIGHT_LIMIT) {
            delete el.dataset.highlighted;
            hljs.highlightElement(el);
        }
    },
    async text() {
        if (!this.truncated || !load) {
            return this.fullText ?? this.$refs.code.textContent;
        }

        this.loading = true;
        try {
            this.fullText ??= await load();
        } finally {
            this.loading = false;
        }

        return this.fullText;
    },
    async showAll() {
        const text = await this.text();
        this.$refs.code.textContent = text;
        this.truncated = false;
        this.highlight();
    },
    async copy() {
        await copyText(await this.text());
        this.copied = true;
        toast('Kode disalin ke clipboard');
        setTimeout(() => (this.copied = false), 1500);
    },
}));

// Preview isi file (Excel via SheetJS, JSON/teks via highlight.js)
Alpine.data('filePreview', (url, extension) => ({
    loading: true,
    error: null,
    kind: null,
    sheets: [],
    active: 0,
    html: '',
    text: '',
    workbook: null,
    async init() {
        if (!['xlsx', 'xls', 'csv', 'json', 'txt', 'js'].includes(extension)) {
            this.loading = false;
            return;
        }

        try {
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) throw new Error(`Gagal memuat file (${response.status})`);

            if (['xlsx', 'xls', 'csv'].includes(extension)) {
                const XLSX = await import('xlsx');
                this.workbook = XLSX.read(await response.arrayBuffer(), { type: 'array' });
                this.sheets = this.workbook.SheetNames;
                this.kind = 'sheet';
                this.renderSheet(0, XLSX);
            } else {
                let text = await response.text();
                if (extension === 'json') {
                    try {
                        text = JSON.stringify(JSON.parse(text), null, 2);
                    } catch (e) {}
                }
                this.text = text;
                this.kind = 'text';
                this.$nextTick(() => {
                    const lang = extension === 'json' ? 'json' : extension === 'js' ? 'javascript' : 'plaintext';
                    this.$refs.text.innerHTML = hljs.highlight(text, { language: lang }).value;
                });
            }
        } catch (e) {
            this.error = e.message;
        } finally {
            this.loading = false;
        }
    },
    async renderSheet(index, XLSX = null) {
        XLSX ??= await import('xlsx');
        this.active = index;
        const sheet = this.workbook.Sheets[this.sheets[index]];
        this.html = XLSX.utils.sheet_to_html(sheet, { header: '', footer: '' });
    },
    async copyText() {
        await copyText(this.text);
        toast('Isi file disalin');
    },
}));

Livewire.start();
