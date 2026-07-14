/**
 * Download blob response from API export endpoints.
 *
 * @param {Blob|ArrayBuffer} data
 * @param {string} filename
 */
export function downloadBlob(data, filename) {
    const url = window.URL.createObjectURL(new Blob([data]));
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
}
