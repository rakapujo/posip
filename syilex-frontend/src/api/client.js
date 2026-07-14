import axios from 'axios';

const client = axios.create({
    baseURL: import.meta.env.VITE_API_URL || '/api/v1',
    headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json'
    }
});

// Request interceptor - attach token
client.interceptors.request.use((config) => {
    const token = localStorage.getItem('token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

// Map generic English HTTP error messages to user-friendly Indonesian.
// Backend often returns { message: "Unauthorized" } or "Forbidden" which looks
// ugly in toast notifications. Custom backend messages (e.g. "Stok tidak cukup")
// are preserved as-is.
const GENERIC_MSG_MAP = {
    unauthorized: 'Sesi Anda berakhir atau Anda tidak memiliki akses.',
    forbidden: 'Anda tidak memiliki akses untuk aksi ini.',
    'not found': 'Data tidak ditemukan.',
    'unauthenticated.': 'Sesi Anda berakhir. Silakan login ulang.',
    'internal server error': 'Server sedang bermasalah. Coba lagi beberapa saat.'
};

// Response interceptor - handle errors
client.interceptors.response.use(
    (response) => response,
    (error) => {
        // Translate generic HTTP messages to Indonesian (in-place on response.data)
        const msg = error.response?.data?.message;
        if (typeof msg === 'string') {
            const friendly = GENERIC_MSG_MAP[msg.trim().toLowerCase()];
            if (friendly) error.response.data.message = friendly;
        }

        // Skip 401 handling for login endpoint (401 is expected for wrong credentials)
        const isLoginEndpoint = error.config?.url?.includes('/auth/login');

        if (error.response?.status === 401 && !isLoginEndpoint) {
            localStorage.removeItem('token');
            localStorage.removeItem('user');
            localStorage.removeItem('permissions');
            window.location.href = '/';
        }
        return Promise.reject(error);
    }
);

export default client;
