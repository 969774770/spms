/* ============================================================
 * 自动上传更新文件（对接软件发布管理系统 spms）
 * 用法：
 *   autoupload.exe <文件路径> [更多文件路径...]
 *     —— 与设置里的规则匹配后自动上传到对应软件/文件夹
 *     —— 无匹配规则时静默退出
 *   autoupload.exe            （双击/无参数）
 *     —— 打开设置界面
 * 依赖：全部使用 Windows 系统库（WinHTTP/BCrypt/DPAPI/Shlwapi）
 *      + cJSON 单文件库（MIT）
 * 编译：gcc -O2 -s -municode -mwindows autoupload.c cJSON.c -o autoupload.exe
 *      -lwinhttp -lbcrypt -lcrypt32 -lshlwapi -lshell32 -ladvapi32 -lcomctl32 -static
 * ============================================================ */
#define WIN32_LEAN_AND_MEAN
#include <windows.h>
#include <winhttp.h>
#include <bcrypt.h>
#include <wincrypt.h>
#include <shlwapi.h>
#include <shellapi.h>
#include <windowsx.h>
#include <locale.h>
#include <stdio.h>
#include <stdlib.h>
#include "cJSON.h"

/* AU_DEBUG 编译时输出过程日志到控制台 */
#ifdef AU_DEBUG
#define LOGW(...) do { wprintf(__VA_ARGS__); wprintf(L"\n"); } while (0)
#else
#define LOGW(...)
#endif

#pragma comment(lib, "winhttp")
#pragma comment(lib, "bcrypt")
#pragma comment(lib, "crypt32")
#pragma comment(lib, "shlwapi")
#pragma comment(lib, "shell32")

/* ---------------- 控件 ID ---------------- */
#define IDC_SERVER   2001
#define IDC_USER     2002
#define IDC_PASS     2003
#define IDC_SAVEACC  2004
#define IDC_LOCAL    2101
#define IDC_DIR      2102
#define IDC_SID      2103
#define IDC_ADDRULE  2104
#define IDC_LIST     2201
#define IDC_DELRULE  2202
#define IDC_TIP      2301
#define WM_TRAY      (WM_APP + 1)

/* ---------------- 全局状态 ---------------- */
static WCHAR g_iniPath[MAX_PATH];      /* autoupload.ini 路径 */
static WCHAR g_server[512];            /* https://updata.wwww.cab */
static WCHAR g_cookie[1024];           /* PHPSESSID=... */
static char  g_csrf[128];              /* csrf_token (utf8) */
static HINTERNET g_hSession = NULL;    /* WinHTTP 会话 */
static HINSTANCE g_hInst;

/* ============================================================
 * 小工具
 * ============================================================ */

/* 宽字符 → UTF-8（返回需 LocalFree 的堆内存） */
static char *WideToUtf8(const WCHAR *ws)
{
    int n = WideCharToMultiByte(CP_UTF8, 0, ws, -1, NULL, 0, NULL, NULL);
    char *s = (char *)HeapAlloc(GetProcessHeap(), 0, n ? n : 1);
    if (s && n) WideCharToMultiByte(CP_UTF8, 0, ws, -1, s, n, NULL, NULL);
    return s;
}
/* UTF-8 → 宽字符（返回需 LocalFree 的堆内存） */
static WCHAR *Utf8ToWide(const char *s)
{
    int n = MultiByteToWideChar(CP_UTF8, 0, s, -1, NULL, 0);
    WCHAR *ws = (WCHAR *)HeapAlloc(GetProcessHeap(), 0, n * sizeof(WCHAR));
    if (ws && n) MultiByteToWideChar(CP_UTF8, 0, s, -1, ws, n);
    return ws;
}

/* exe 同目录 ini 路径；首次创建时写 UTF-16 BOM 以支持中文 */
static void init_ini_path(void)
{
    WCHAR exe[MAX_PATH];
    GetModuleFileNameW(NULL, exe, MAX_PATH);
    WCHAR *slash = wcsrchr(exe, L'\\');
    if (slash) *(slash + 1) = 0;
    lstrcpynW(g_iniPath, exe, MAX_PATH);
    lstrcatW(g_iniPath, L"autoupload.ini");
    if (GetFileAttributesW(g_iniPath) == INVALID_FILE_ATTRIBUTES) {
        HANDLE h = CreateFileW(g_iniPath, GENERIC_WRITE, 0, NULL, CREATE_ALWAYS, 0, NULL);
        if (h != INVALID_HANDLE_VALUE) {
            const BYTE bom[2] = { 0xFF, 0xFE };
            DWORD w = 0;
            WriteFile(h, bom, 2, &w, NULL);
            CloseHandle(h);
        }
    }
}

/* ============================================================
 * 配置读写（INI）
 * ============================================================ */
typedef struct {
    WCHAR local[512];   /* 本地文件/目录/通配符 */
    WCHAR dir[512];     /* 服务器目标文件夹 */
    WCHAR sid[32];      /* 软件 ID，如 S0006 */
} RULE;

static int config_load_rules(RULE *out, int maxRules)
{
    WCHAR buf[16];
    GetPrivateProfileStringW(L"rules", L"count", L"0", buf, 16, g_iniPath);
    int count = _wtoi(buf);
    if (count > maxRules) count = maxRules;
    for (int i = 0; i < count; i++) {
        WCHAR sec[32];
        wsprintfW(sec, L"rule%d", i);
        GetPrivateProfileStringW(sec, L"local", L"", out[i].local, 512, g_iniPath);
        GetPrivateProfileStringW(sec, L"dir",   L"", out[i].dir,   512, g_iniPath);
        GetPrivateProfileStringW(sec, L"sid",   L"", out[i].sid,   32,  g_iniPath);
    }
    return count;
}

static void config_save_rules(const RULE *rules, int count)
{
    WCHAR buf[16];
    /* 先清掉可能残留的旧小节 */
    for (int i = 0; i < count + 64; i++) {
        WCHAR sec[32];
        wsprintfW(sec, L"rule%d", i);
        WritePrivateProfileStringW(sec, NULL, NULL, g_iniPath);
    }
    for (int i = 0; i < count; i++) {
        WCHAR sec[32];
        wsprintfW(sec, L"rule%d", i);
        WritePrivateProfileStringW(sec, L"local", rules[i].local, g_iniPath);
        WritePrivateProfileStringW(sec, L"dir",   rules[i].dir,   g_iniPath);
        WritePrivateProfileStringW(sec, L"sid",   rules[i].sid,   g_iniPath);
    }
    wsprintfW(buf, L"%d", count);
    WritePrivateProfileStringW(L"rules", L"count", buf, g_iniPath);
}

static void config_load_account(WCHAR *server, WCHAR *user, WCHAR *pass)
{
    GetPrivateProfileStringW(L"account", L"server", L"https://updata.wwww.cab", server, 512, g_iniPath);
    GetPrivateProfileStringW(L"account", L"user",   L"", user, 64, g_iniPath);
    /* 密码：DPAPI 加密的 base64，解密后还原 */
    WCHAR b64[1024];
    GetPrivateProfileStringW(L"account", L"pass_enc", L"", b64, 1024, g_iniPath);
    pass[0] = 0;
    if (b64[0]) {
        DWORD binLen = 0;
        CryptStringToBinaryW(b64, 0, CRYPT_STRING_BASE64, NULL, &binLen, NULL, NULL);
        if (binLen > 0 && binLen < 4096) {
            BYTE *bin = (BYTE *)HeapAlloc(GetProcessHeap(), 0, binLen);
            if (bin && CryptStringToBinaryW(b64, 0, CRYPT_STRING_BASE64, bin, &binLen, NULL, NULL)) {
                DATA_BLOB in, out;
                in.cbData = binLen;
                in.pbData = bin;
                if (CryptUnprotectData(&in, NULL, NULL, NULL, NULL, 0, &out)) {
                    int chars = out.cbData / sizeof(WCHAR);
                    if (chars > 0 && out.pbData[0]) {
                        lstrcpynW(pass, (WCHAR *)out.pbData, chars > 127 ? 127 : chars);
                    }
                    LocalFree(out.pbData);
                }
            }
            HeapFree(GetProcessHeap(), 0, bin);
        }
    }
}

static void config_save_account(const WCHAR *server, const WCHAR *user, const WCHAR *pass)
{
    WritePrivateProfileStringW(L"account", L"server", server, g_iniPath);
    WritePrivateProfileStringW(L"account", L"user", user, g_iniPath);
    /* DPAPI 加密（当前用户级，换机器/换用户无法解密） */
    DATA_BLOB in, out = { 0 };
    in.cbData = (DWORD)((lstrlenW(pass) + 1) * sizeof(WCHAR));
    in.pbData = (BYTE *)pass;
    if (CryptProtectData(&in, L"spms-autoupload", NULL, NULL, NULL, 0, &out)) {
        DWORD b64Len = 0;
        CryptBinaryToStringW(out.pbData, out.cbData, CRYPT_STRING_BASE64 | CRYPT_STRING_NOCRLF, NULL, &b64Len);
        WCHAR *b64 = (WCHAR *)HeapAlloc(GetProcessHeap(), 0, b64Len * sizeof(WCHAR));
        if (b64 && CryptBinaryToStringW(out.pbData, out.cbData, CRYPT_STRING_BASE64 | CRYPT_STRING_NOCRLF, b64, &b64Len)) {
            WritePrivateProfileStringW(L"account", L"pass_enc", b64, g_iniPath);
        }
        HeapFree(GetProcessHeap(), 0, b64);
        LocalFree(out.pbData);
    }
}

/* ============================================================
 * 规则匹配：全路径相等 / 目录前缀 / 文件名通配符，任一命中
 * ============================================================ */
static BOOL rule_match(const WCHAR *pattern, const WCHAR *filePath)
{
    if (!pattern[0]) return FALSE;
    /* 1) 完整路径相等（不区分大小写） */
    if (lstrcmpiW(pattern, filePath) == 0) return TRUE;
    /* 2) 目录前缀：pattern 是目录（以 \ 结尾或本地存在该目录），文件在其下 */
    WCHAR pfx[512];
    lstrcpynW(pfx, pattern, 512);
    int len = lstrlenW(pfx);
    BOOL isDir = FALSE;
    if (len > 0 && (pfx[len - 1] == L'\\' || pfx[len - 1] == L'/')) {
        isDir = TRUE;
        pfx[len - 1] = 0;
    } else if (GetFileAttributesW(pfx) != INVALID_FILE_ATTRIBUTES
               && (GetFileAttributesW(pfx) & FILE_ATTRIBUTE_DIRECTORY)) {
        isDir = TRUE;
    }
    if (isDir) {
        len = lstrlenW(pfx);
        if (wcsnicmp(pfx, filePath, len) == 0
            && (filePath[len] == L'\\' || filePath[len] == L'/')) {
            return TRUE;
        }
        return FALSE;
    }
    /* 3) 通配符匹配文件名（如 *.zip），也允许匹配完整路径 */
    const WCHAR *fname = wcsrchr(filePath, L'\\');
    fname = fname ? fname + 1 : filePath;
    if (wcschr(pattern, L'*') || wcschr(pattern, L'?')) {
        if (PathMatchSpecW(fname, pattern)) return TRUE;
        return PathMatchSpecW(filePath, pattern);
    }
    return FALSE;
}

/* ============================================================
 * 文件 SHA-256（BCrypt CNG 流式计算）→ 64 位 hex 字符串(utf8)
 * ============================================================ */
static int sha256_file_hex(const WCHAR *path, char *hexOut /* 65 字节 */)
{
    BCRYPT_ALG_HANDLE alg = NULL;
    BCRYPT_HASH_HANDLE hash = NULL;
    HANDLE h = NULL;
    int ok = 0;
    static BYTE buf[4 * 1024 * 1024];
    BYTE digest[32];

    if (FAILED(BCryptOpenAlgorithmProvider(&alg, BCRYPT_SHA256_ALGORITHM, NULL, 0))) goto done;
    if (FAILED(BCryptCreateHash(alg, &hash, NULL, 0, NULL, 0, 0))) goto done;
    h = CreateFileW(path, GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, NULL,
                    OPEN_EXISTING, 0, NULL);
    if (h == INVALID_HANDLE_VALUE) goto done;
    for (;;) {
        DWORD rd = 0;
        if (!ReadFile(h, buf, sizeof(buf), &rd, NULL)) goto done;
        if (rd == 0) break;
        if (FAILED(BCryptHashData(hash, buf, rd, 0))) goto done;
    }
    if (FAILED(BCryptFinishHash(hash, digest, 32, 0))) goto done;
    for (int i = 0; i < 32; i++) wsprintfA(hexOut + i * 2, "%02x", digest[i]);
    hexOut[64] = 0;
    ok = 1;
done:
    if (h && h != INVALID_HANDLE_VALUE) CloseHandle(h);
    if (hash) BCryptDestroyHash(hash);
    if (alg) BCryptCloseAlgorithmProvider(alg, 0);
    return ok;
}

/* ============================================================
 * HTTP（WinHTTP）：统一入口
 *   method: L"GET"/L"POST"
 *   path:   /api.php?action=...
 *   body/bodyLen: POST 内容（JSON 为 utf8；分片为二进制）
 *   contentType: L"application/json" / L"application/octet-stream" / NULL
 *   resp: 输出响应 body（utf8 堆内存，需 HeapFree；可为 NULL）
 * 返回 HTTP 状态码，0=网络错误
 * ============================================================ */
static DWORD http_request(const WCHAR *method, const WCHAR *path,
                          const BYTE *body, DWORD bodyLen, const WCHAR *contentType,
                          char **resp)
{
    DWORD status = 0;
    HINTERNET hConnect = NULL, hRequest = NULL;
    WCHAR host[256], scheme[32];
    URL_COMPONENTS uc = { sizeof(uc) };
    uc.dwHostNameLength = 256; uc.lpszHostName = host;
    uc.dwSchemeLength   = 32;  uc.lpszScheme = scheme;
    uc.dwUrlPathLength  = (DWORD)-1;
    uc.dwExtraInfoLength = (DWORD)-1;
    if (!WinHttpCrackUrl(g_server, 0, 0, &uc)) goto done;
    if (!g_hSession) goto done;
    hConnect = WinHttpConnect(g_hSession, host, uc.nPort, 0);
    if (!hConnect) goto done;
    DWORD flags = (uc.nScheme == INTERNET_SCHEME_HTTPS) ? WINHTTP_FLAG_SECURE : 0;
    hRequest = WinHttpOpenRequest(hConnect, method, path, NULL, WINHTTP_NO_REFERER,
                                  WINHTTP_DEFAULT_ACCEPT_TYPES, flags);
    if (!hRequest) goto done;
    if (flags) {
        /* 兼容自签/证书链不完整的服务器 */
        DWORD secFlags = SECURITY_FLAG_IGNORE_UNKNOWN_CA | SECURITY_FLAG_IGNORE_CERT_CN_INVALID
                       | SECURITY_FLAG_IGNORE_CERT_DATE_INVALID | SECURITY_FLAG_IGNORE_CERT_WRONG_USAGE;
        WinHttpSetOption(hRequest, WINHTTP_OPTION_SECURITY_FLAGS, &secFlags, sizeof(secFlags));
    }
    {
        WCHAR hdr[1400];
        WCHAR ct[128];
        ct[0] = 0;
        if (contentType) wsprintfW(ct, L"Content-Type: %s\r\n", contentType);
        wsprintfW(hdr, L"%sX-CSRF-Token: %S\r\nCookie: %s\r\n", ct, g_csrf, g_cookie);
        DWORD hdrLen = (DWORD)lstrlenW(hdr);
        if (!WinHttpSendRequest(hRequest, hdr, hdrLen,
                                (LPVOID)(bodyLen ? body : WINHTTP_NO_REQUEST_DATA),
                                bodyLen, bodyLen, 0)) {
            goto done;
        }
    }
    if (!WinHttpReceiveResponse(hRequest, NULL)) goto done;
    {
        DWORD sz = sizeof(status);
        WinHttpQueryHeaders(hRequest, WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,
                            WINHTTP_HEADER_NAME_BY_INDEX, &status, &sz, WINHTTP_NO_HEADER_INDEX);
        /* 记录会话 Cookie（auth.init 返回 PHPSESSID） */
        DWORD rawLen = 0;
        WinHttpQueryHeaders(hRequest, WINHTTP_QUERY_RAW_HEADERS_CRLF,
                            WINHTTP_HEADER_NAME_BY_INDEX, NULL, &rawLen, WINHTTP_NO_HEADER_INDEX);
        if (GetLastError() == ERROR_INSUFFICIENT_BUFFER) {
            WCHAR *raw = (WCHAR *)HeapAlloc(GetProcessHeap(), 0, rawLen * sizeof(WCHAR));
            if (raw && WinHttpQueryHeaders(hRequest, WINHTTP_QUERY_RAW_HEADERS_CRLF,
                                           WINHTTP_HEADER_NAME_BY_INDEX, raw, &rawLen,
                                           WINHTTP_NO_HEADER_INDEX)) {
                const WCHAR *p = raw;
                while ((p = wcsstr(p, L"Set-Cookie:")) != NULL) {
                    p += 11;
                    while (*p == L' ') p++;
                    if (wcsnicmp(p, L"PHPSESSID=", 10) == 0) {
                        const WCHAR *end = wcschr(p, L';');
                        int n = end ? (int)(end - p) : (int)wcslen(p);
                        if (n > 0 && n < 900) {
                            lstrcpynW(g_cookie, p, n + 1);
                        }
                        break;
                    }
                    p += 11;
                }
            }
            HeapFree(GetProcessHeap(), 0, raw);
        }
    }
    if (resp) {
        *resp = NULL;
        DWORD total = 0, cap = 8192;
        char *acc = (char *)HeapAlloc(GetProcessHeap(), 0, cap);
        for (;;) {
            DWORD rd = 0;
            char chunk[8192];
            if (!WinHttpReadData(hRequest, chunk, sizeof(chunk), &rd) || rd == 0) break;
            if (total + rd + 1 > cap) {
                cap = (total + rd + 1) * 2;
                char *acc2 = (char *)HeapReAlloc(GetProcessHeap(), 0, acc, cap);
                if (!acc2) break;
                acc = acc2;
            }
            memcpy(acc + total, chunk, rd);
            total += rd;
        }
        acc[total] = 0;
        *resp = acc;
    }
done:
    if (hRequest) WinHttpCloseHandle(hRequest);
    if (hConnect) WinHttpCloseHandle(hConnect);
    return status;
}

/* 初始化 WinHTTP 会话 */
static BOOL http_init(void)
{
    if (!g_hSession) {
        g_hSession = WinHttpOpen(L"spms-autoupload/1.0", WINHTTP_ACCESS_TYPE_DEFAULT_PROXY,
                                 WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, 0);
    }
    return g_hSession != NULL;
}

/* ============================================================
 * 登录：auth.init → auth.login（成功返回 0，失败返回错误文案到 err）
 * ============================================================ */
static BOOL api_login(const WCHAR *user, const WCHAR *pass, WCHAR *err, int errLen)
{
    char *resp = NULL;
    err[0] = 0;
    g_csrf[0] = 0;
    g_cookie[0] = 0;
    /* 1) init：拿匿名会话与 csrf_token */
    if (!http_init() || http_request(L"GET", L"/api.php?action=auth.init", NULL, 0, NULL, &resp) != 200) {
        LOGW(L"[login] auth.init failed");
        lstrcpynW(err, L"无法连接服务器", errLen);
        return FALSE;
    }
    LOGW(L"[login] auth.init ok");
    cJSON *j = cJSON_Parse(resp);
    if (j) {
        cJSON *tok = cJSON_GetObjectItemCaseSensitive(j, "data");
        tok = tok ? cJSON_GetObjectItemCaseSensitive(tok, "csrf_token") : NULL;
        if (cJSON_IsString(tok) && tok->valuestring) {
            lstrcpynA(g_csrf, tok->valuestring, 128);
        }
        cJSON_Delete(j);
    }
    HeapFree(GetProcessHeap(), 0, resp);
    if (!g_csrf[0]) {
        lstrcpynW(err, L"初始化会话失败", errLen);
        return FALSE;
    }
    /* 2) login */
    {
        char *user8 = WideToUtf8(user);
        char *pass8 = WideToUtf8(pass);
        cJSON *body = cJSON_CreateObject();
        cJSON_AddStringToObject(body, "username", user8 ? user8 : "");
        cJSON_AddStringToObject(body, "password", pass8 ? pass8 : "");
        char *json = cJSON_PrintUnformatted(body);
        resp = NULL;
        DWORD st = http_request(L"POST", L"/api.php?action=auth.login",
                                (const BYTE *)json, (DWORD)strlen(json), L"application/json", &resp);
        LOGW(L"[login] status=%d", (int)st);
        HeapFree(GetProcessHeap(), 0, user8);
        HeapFree(GetProcessHeap(), 0, pass8);
        cJSON_Delete(body);
        BOOL ok = FALSE;
        if (st == 200 && resp) {
            j = cJSON_Parse(resp);
            cJSON *okj = j ? cJSON_GetObjectItemCaseSensitive(j, "ok") : NULL;
            ok = cJSON_IsTrue(okj);
            /* 登录成功后服务端轮换 csrf_token，必须更新，否则后续 POST 全部 403 */
            cJSON *d = j ? cJSON_GetObjectItemCaseSensitive(j, "data") : NULL;
            cJSON *tok = d ? cJSON_GetObjectItemCaseSensitive(d, "csrf_token") : NULL;
            if (cJSON_IsString(tok) && tok->valuestring) {
                lstrcpynA(g_csrf, tok->valuestring, 128);
                LOGW(L"[login] csrf refreshed");
            }
            if (!ok && j) {
                cJSON *e = cJSON_GetObjectItemCaseSensitive(j, "error");
                cJSON *m = e ? cJSON_GetObjectItemCaseSensitive(e, "message") : NULL;
                if (cJSON_IsString(m) && m->valuestring) {
                    WCHAR *w = Utf8ToWide(m->valuestring);
                    if (w) { lstrcpynW(err, w, errLen); HeapFree(GetProcessHeap(), 0, w); }
                }
            }
            if (j) cJSON_Delete(j);
        }
        HeapFree(GetProcessHeap(), 0, resp);
        if (json) cJSON_free(json);
        if (!ok && !err[0]) lstrcpynW(err, L"登录失败", errLen);
        return ok;
    }
}

/* ============================================================
 * 上传单个文件到指定软件/文件夹
 * 成功返回 TRUE；err 返回失败原因
 * ============================================================ */
static BOOL api_upload_one(const WCHAR *sid, const WCHAR *dirPath, const WCHAR *filePath,
                           WCHAR *err, int errLen)
{
    BOOL ok = FALSE;
    HANDLE hFile = NULL;
    char hex[65];
    char *sid8 = NULL, *dir8 = NULL, *name8 = NULL, *json = NULL, *resp = NULL;
    cJSON *body = NULL;
    err[0] = 0;

    const WCHAR *fname = wcsrchr(filePath, L'\\');
    fname = fname ? fname + 1 : filePath;

    hFile = CreateFileW(filePath, GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, NULL,
                        OPEN_EXISTING, 0, NULL);
    if (hFile == INVALID_HANDLE_VALUE) {
        lstrcpynW(err, L"文件无法读取（可能被占用）", errLen);
        goto done;
    }
    LARGE_INTEGER fsize;
    if (!GetFileSizeEx(hFile, &fsize)) {
        lstrcpynW(err, L"获取文件大小失败", errLen);
        goto done;
    }
    CloseHandle(hFile);
    hFile = NULL;

    if (!sha256_file_hex(filePath, hex)) {
        lstrcpynW(err, L"计算哈希失败", errLen);
        goto done;
    }
    sid8 = WideToUtf8(sid);
    dir8 = WideToUtf8(dirPath ? dirPath : L"");
    name8 = WideToUtf8(fname);
    if (!sid8 || !dir8 || !name8) {
        lstrcpynW(err, L"内存不足", errLen);
        goto done;
    }

    /* probe：秒传检测（服务端直接落位） */
    body = cJSON_CreateObject();
    cJSON_AddStringToObject(body, "software_id", sid8);
    cJSON_AddStringToObject(body, "dir_path", dir8);
    cJSON_AddStringToObject(body, "file_name", name8);
    cJSON_AddNumberToObject(body, "size", (double)fsize.QuadPart);
    cJSON_AddStringToObject(body, "hash", hex);
    json = cJSON_PrintUnformatted(body);
    {
        DWORD st = http_request(L"POST", L"/api.php?action=upload.probe",
                                (const BYTE *)json, (DWORD)strlen(json), L"application/json", &resp);
        LOGW(L"[probe] status=%d", (int)st);
        BOOL instant = FALSE;
        if (st == 200 && resp) {
            cJSON *j = cJSON_Parse(resp);
            cJSON *d = j ? cJSON_GetObjectItemCaseSensitive(j, "data") : NULL;
            cJSON *ins = d ? cJSON_GetObjectItemCaseSensitive(d, "instant") : NULL;
            instant = cJSON_IsTrue(ins);
            if (j) cJSON_Delete(j);
        }
        HeapFree(GetProcessHeap(), 0, resp);
        resp = NULL;
        if (instant) {
            ok = TRUE;
            goto done;   /* 秒传完成 */
        }
    }

    /* init：创建上传会话 */
    {
        char uuid[64] = { 0 };
        int chunk = 5 * 1024 * 1024, total = 1;
        {
            DWORD st = http_request(L"POST", L"/api.php?action=upload.init",
                                    (const BYTE *)json, (DWORD)strlen(json), L"application/json", &resp);
            LOGW(L"[init] status=%d", (int)st);
            if (resp) LOGW(L"[init] resp=%.300S", resp);
            BOOL good = FALSE;
            if (st == 200 && resp) {
                cJSON *j = cJSON_Parse(resp);
                cJSON *d = j ? cJSON_GetObjectItemCaseSensitive(j, "data") : NULL;
                if (d) {
                    cJSON *u = cJSON_GetObjectItemCaseSensitive(d, "uuid");
                    cJSON *c = cJSON_GetObjectItemCaseSensitive(d, "chunk_size");
                    cJSON *t = cJSON_GetObjectItemCaseSensitive(d, "total_parts");
                    if (cJSON_IsString(u) && u->valuestring) {
                        lstrcpynA(uuid, u->valuestring, 64);
                        chunk = cJSON_IsNumber(c) ? (int)c->valuedouble : chunk;
                        total = cJSON_IsNumber(t) ? (int)t->valuedouble : 1;
                        good = TRUE;
                    }
                }
                if (j) cJSON_Delete(j);
            }
            HeapFree(GetProcessHeap(), 0, resp);
            resp = NULL;
            LOGW(L"[init] good=%d uuid=%S chunk=%d total=%d", good, uuid, chunk, total);
            if (!good) {
                lstrcpynW(err, L"创建上传会话失败", errLen);
                goto done;
            }
        }

        /* part：逐片上传（最后一片按剩余大小） */
        LOGW(L"[part-begin]");
        hFile = CreateFileW(filePath, GENERIC_READ, FILE_SHARE_READ | FILE_SHARE_WRITE, NULL,
                            OPEN_EXISTING, 0, NULL);
        if (hFile == INVALID_HANDLE_VALUE) {
            lstrcpynW(err, L"文件无法读取", errLen);
            goto done;
        }
        static BYTE *partBuf = NULL;
        static int partBufSize = 0;
        if (chunk > partBufSize) {
            /* 注意：HeapReAlloc 不接受 NULL，首次需 HeapAlloc */
            BYTE *nb = (BYTE *)(partBuf
                ? HeapReAlloc(GetProcessHeap(), 0, partBuf, chunk + 1)
                : HeapAlloc(GetProcessHeap(), 0, chunk + 1));
            if (!nb) { lstrcpynW(err, L"内存不足", errLen); goto done; }
            partBuf = nb;
            partBufSize = chunk;
        }
        for (int p = 1; p <= total; p++) {
            DWORD want = (p == total)
                ? (DWORD)(fsize.QuadPart - (LONGLONG)chunk * (total - 1))
                : (DWORD)chunk;
            if (want == 0) want = 0; /* 空文件单片 */
            DWORD rd = 0;
            if (want > 0 && (!ReadFile(hFile, partBuf, want, &rd, NULL) || rd != want)) {
                wsprintfW(err, L"读取分片 %d 失败", p);
                goto done;
            }
            WCHAR url[160];
            wsprintfW(url, L"/api.php?action=upload.part&uuid=%S&part=%d", uuid, p);
            DWORD st = 0;
            BOOL sent = FALSE;
            for (int retry = 0; retry < 3 && !sent; retry++) {
                st = http_request(L"POST", url, partBuf, rd, L"application/octet-stream", &resp);
                LOGW(L"[part %d] status=%d retry=%d", p, (int)st, retry);
                if (st == 200 && resp) {
                    cJSON *j = cJSON_Parse(resp);
                    cJSON *okj = j ? cJSON_GetObjectItemCaseSensitive(j, "ok") : NULL;
                    sent = cJSON_IsTrue(okj);
                    if (j) cJSON_Delete(j);
                }
                HeapFree(GetProcessHeap(), 0, resp);
                resp = NULL;
                if (!sent) Sleep(1000);
            }
            if (!sent) {
                wsprintfW(err, L"分片 %d/%d 上传失败", p, total);
                goto done;
            }
        }
        CloseHandle(hFile);
        hFile = NULL;

        /* finish */
        if (json) { cJSON_free(json); json = NULL; }
        cJSON_Delete(body);
        body = cJSON_CreateObject();
        cJSON_AddStringToObject(body, "uuid", uuid);
        json = cJSON_PrintUnformatted(body);
        DWORD st = http_request(L"POST", L"/api.php?action=upload.finish",
                                (const BYTE *)json, (DWORD)strlen(json), L"application/json", &resp);
        LOGW(L"[finish] status=%d", (int)st);
        if (st == 200 && resp) {
            cJSON *j = cJSON_Parse(resp);
            cJSON *okj = j ? cJSON_GetObjectItemCaseSensitive(j, "ok") : NULL;
            ok = cJSON_IsTrue(okj);
            if (!ok && j) {
                cJSON *e = cJSON_GetObjectItemCaseSensitive(j, "error");
                cJSON *m = e ? cJSON_GetObjectItemCaseSensitive(e, "message") : NULL;
                if (cJSON_IsString(m) && m->valuestring) {
                    WCHAR *w = Utf8ToWide(m->valuestring);
                    if (w) { lstrcpynW(err, w, errLen); HeapFree(GetProcessHeap(), 0, w); }
                }
            }
            if (j) cJSON_Delete(j);
        }
        HeapFree(GetProcessHeap(), 0, resp);
        resp = NULL;
        if (!ok && !err[0]) lstrcpynW(err, L"完成上传失败", errLen);
    }
done:
    LOGW(L"[done] ok=%d err=%s", ok, err && err[0] ? err : L"-");
    if (hFile && hFile != INVALID_HANDLE_VALUE) CloseHandle(hFile);
    if (sid8) HeapFree(GetProcessHeap(), 0, sid8);
    if (dir8) HeapFree(GetProcessHeap(), 0, dir8);
    if (name8) HeapFree(GetProcessHeap(), 0, name8);
    if (body) cJSON_Delete(body);
    if (json) cJSON_free(json);
    if (resp) HeapFree(GetProcessHeap(), 0, resp);
    return ok;
}

/* ============================================================
 * 系统托盘气泡通知（尽量少打扰：一条汇总，几秒后自动消失）
 * ============================================================ */
static LRESULT CALLBACK trayWndProc(HWND w, UINT m, WPARAM wp, LPARAM lp) { return DefWindowProcW(w, m, wp, lp); }

static void notify_result(const WCHAR *title, const WCHAR *text, DWORD infoFlags)
{
    WNDCLASSW wc = { 0 };
    wc.lpfnWndProc = trayWndProc;
    wc.hInstance = g_hInst;
    wc.lpszClassName = L"spms_autoupload_tray";
    RegisterClassW(&wc);
    HWND w = CreateWindowExW(0, wc.lpszClassName, L"", 0, 0, 0, 0, 0, HWND_MESSAGE, NULL, g_hInst, NULL);
    NOTIFYICONDATAW ni = { sizeof(ni) };
    ni.hWnd = w;
    ni.uID = 1;
    ni.uFlags = NIF_MESSAGE | NIF_ICON | NIF_TIP;
    ni.uCallbackMessage = WM_TRAY;
    ni.hIcon = LoadIconW(NULL, IDI_APPLICATION);
    Shell_NotifyIconW(NIM_ADD, &ni);
    ni.uFlags = NIF_INFO;
    ni.dwInfoFlags = infoFlags;   /* NIIF_INFO / NIIF_ERROR */
    ni.uTimeout = 5000;
    lstrcpynW(ni.szInfoTitle, title, 64);
    lstrcpynW(ni.szInfo, text, 256);
    Shell_NotifyIconW(NIM_MODIFY, &ni);
    Sleep(5000);   /* 等气泡展示 */
    Shell_NotifyIconW(NIM_DELETE, &ni);
    DestroyWindow(w);
    UnregisterClassW(wc.lpszClassName, g_hInst);
}

/* ============================================================
 * 命令行上传流程
 * ============================================================ */
static int run_cli(WCHAR **argv, int argc)
{
    RULE rules[64];
    int ruleCount = config_load_rules(rules, 64);
    if (ruleCount <= 0) return 0;               /* 无规则：静默退出 */

    WCHAR accServer[512], accUser[64], accPass[128];
    config_load_account(accServer, accUser, accPass);
    lstrcpynW(g_server, accServer, 512);

    /* 收集命中：(规则索引, 文件路径) */
    struct { int rule; const WCHAR *file; } hits[128];
    int hitCount = 0;
    for (int a = 1; a < argc && hitCount < 128; a++) {
        for (int r = 0; r < ruleCount && hitCount < 128; r++) {
            if (rule_match(rules[r].local, argv[a])) {
                hits[hitCount].rule = r;
                hits[hitCount].file = argv[a];
                hitCount++;
            }
        }
    }
    if (hitCount == 0) return 0;                /* 无匹配：静默退出，不提示 */

    /* 有匹配才登录并上传 */
    if (!accUser[0]) {
        notify_result(L"自动上传", L"未配置发布系统账号，请先双击程序完成设置", NIIF_ERROR);
        return 1;
    }
    WCHAR err[256];
    if (!api_login(accUser, accPass, err, 256)) {
        WCHAR msg[320];
        wsprintfW(msg, L"登录失败：%s", err);
        notify_result(L"自动上传", msg, NIIF_ERROR);
        return 1;
    }
    /* 同一进程内多次上传：probe/init 会各自重新请求，csrf/cookie 保持登录态即可 */
    int okCount = 0;
    WCHAR detail[512] = L"";
    for (int i = 0; i < hitCount; i++) {
        RULE *r = &rules[hits[i].rule];
        const WCHAR *fname = wcsrchr(hits[i].file, L'\\');
        fname = fname ? fname + 1 : hits[i].file;
        if (api_upload_one(r->sid, r->dir, hits[i].file, err, 256)) {
            okCount++;
            const WCHAR *dn = r->dir[0] ? r->dir : L"/";
            int n = lstrlenW(detail);
            wsprintfW(detail + n, L"%s%s → %s[%s]", n ? L"\n" : L"", fname, dn, r->sid);
        } else {
            int n = lstrlenW(detail);
            wsprintfW(detail + n, L"%s%s 失败：%s", n ? L"\n" : L"", fname, err);
        }
    }
    if (okCount == hitCount) {
        notify_result(L"自动上传：全部成功", detail, NIIF_INFO);
    } else if (okCount == 0) {
        notify_result(L"自动上传：失败", detail, NIIF_ERROR);
    } else {
        notify_result(L"自动上传：部分成功", detail, NIIF_WARNING);
    }
    return okCount == hitCount ? 0 : 2;
}

/* ============================================================
 * 设置界面（GUI）
 * ============================================================ */
static HWND g_hMain, g_hServer, g_hUser, g_hPass, g_hLocal, g_hDir, g_hSid, g_hList, g_hTip;

static void gui_reload_rules(void)
{
    ListBox_ResetContent(g_hList);
    RULE rules[64];
    int n = config_load_rules(rules, 64);
    for (int i = 0; i < n; i++) {
        WCHAR line[1100];
        if (rules[i].dir[0]) {
            wsprintfW(line, L"%s  →  /%s  [%s]", rules[i].local, rules[i].dir, rules[i].sid);
        } else {
            wsprintfW(line, L"%s  →  /  [%s]", rules[i].local, rules[i].sid);
        }
        ListBox_AddString(g_hList, line);
    }
}

static void gui_load_account(void)
{
    WCHAR server[512], user[64], pass[128];
    config_load_account(server, user, pass);
    lstrcpynW(g_server, server, 512);
    Edit_SetText(g_hServer, server);
    Edit_SetText(g_hUser, user);
    Edit_SetText(g_hPass, pass);
}

static void gui_save_account(void)
{
    WCHAR server[512], user[64], pass[128];
    Edit_GetText(g_hServer, server, 512);
    Edit_GetText(g_hUser, user, 64);
    Edit_GetText(g_hPass, pass, 128);
    if (server[0] && user[0]) {
        config_save_account(server, user, pass);
        lstrcpynW(g_server, server, 512);
        SetWindowTextW(g_hTip, L"账号已保存（密码已加密存放在本机）");
    } else {
        SetWindowTextW(g_hTip, L"服务器地址与账号不能为空");
    }
}

static void gui_add_rule(void)
{
    WCHAR local[512], dir[512], sid[32];
    Edit_GetText(g_hLocal, local, 512);
    Edit_GetText(g_hDir, dir, 512);
    Edit_GetText(g_hSid, sid, 32);
    if (!local[0] || !sid[0]) {
        SetWindowTextW(g_hTip, L"「本地路径」与「软件ID」不能为空");
        return;
    }
    RULE rules[65];
    int n = config_load_rules(rules, 64);
    for (int i = 0; i < n; i++) {
        if (lstrcmpiW(rules[i].local, local) == 0 && lstrcmpiW(rules[i].sid, sid) == 0) {
            SetWindowTextW(g_hTip, L"该规则已存在");
            return;
        }
    }
    lstrcpynW(rules[n].local, local, 512);
    lstrcpynW(rules[n].dir, dir, 512);
    lstrcpynW(rules[n].sid, sid, 32);
    n++;
    config_save_rules(rules, n);
    gui_reload_rules();
    Edit_SetText(g_hLocal, L"");
    SetWindowTextW(g_hTip, L"规则已添加");
}

static void gui_del_rule(void)
{
    int sel = ListBox_GetCurSel(g_hList);
    if (sel < 0) {
        SetWindowTextW(g_hTip, L"请先在列表中选中一条规则");
        return;
    }
    RULE rules[64];
    int n = config_load_rules(rules, 64);
    for (int i = sel; i < n - 1; i++) rules[i] = rules[i + 1];
    n--;
    config_save_rules(rules, n);
    gui_reload_rules();
    SetWindowTextW(g_hTip, L"规则已删除");
}

static LRESULT CALLBACK mainWndProc(HWND w, UINT m, WPARAM wp, LPARAM lp)
{
    switch (m) {
    case WM_DROPFILES: {
        /* 拖拽文件到窗口 → 取第一个路径填入「本地路径」 */
        WCHAR path[MAX_PATH];
        UINT n = DragQueryFileW((HDROP)wp, 0xFFFFFFFF, NULL, 0);
        if (n > 0 && DragQueryFileW((HDROP)wp, 0, path, MAX_PATH) > 0) {
            Edit_SetText(g_hLocal, path);
            SetWindowTextW(g_hTip, L"已填入拖入的文件路径（可加通配符如 *.zip）");
        }
        DragFinish((HDROP)wp);
        return 0;
    }
    case WM_COMMAND:
        switch (LOWORD(wp)) {
        case IDC_SAVEACC: gui_save_account(); return 0;
        case IDC_ADDRULE: gui_add_rule(); return 0;
        case IDC_DELRULE: gui_del_rule(); return 0;
        }
        break;
    case WM_CLOSE:
        DestroyWindow(w);
        return 0;
    case WM_DESTROY:
        PostQuitMessage(0);
        return 0;
    }
    return DefWindowProcW(w, m, wp, lp);
}

static HWND gui_make(HWND parent, const WCHAR *cls, const WCHAR *text, DWORD style,
                     int x, int y, int w, int h, int id)
{
    HWND c = CreateWindowExW(0, cls, text, WS_CHILD | WS_VISIBLE | style,
                             x, y, w, h, parent, (HMENU)(INT_PTR)id, g_hInst, NULL);
    SendMessageW(c, WM_SETFONT, (WPARAM)GetStockObject(DEFAULT_GUI_FONT), TRUE);
    return c;
}

static int run_gui(void)
{
    WNDCLASSW wc = { 0 };
    wc.lpfnWndProc = mainWndProc;
    wc.hInstance = g_hInst;
    wc.hCursor = LoadCursorW(NULL, IDC_ARROW);
    wc.hbrBackground = (HBRUSH)(COLOR_BTNFACE + 1);
    wc.lpszClassName = L"spms_autoupload_gui";
    RegisterClassW(&wc);

    int W = 560, H = 470;
    RECT wa;
    SystemParametersInfoW(SPI_GETWORKAREA, 0, &wa, 0);
    /* 在主屏工作区内居中；负值兜底归 0，防止窗口跑到屏幕外 */
    int x = wa.left + ((wa.right - wa.left) - W) / 2;
    int y = wa.top + ((wa.bottom - wa.top) - H) / 2;
    if (x < wa.left) x = wa.left;
    if (y < wa.top) y = wa.top;
    g_hMain = CreateWindowExW(WS_EX_ACCEPTFILES, wc.lpszClassName,
                              L"自动上传 - 设置（软件发布管理系统）",
                              WS_OVERLAPPED | WS_CAPTION | WS_SYSMENU | WS_MINIMIZEBOX,
                              x, y, W, H, NULL, NULL, g_hInst, NULL);
    int mL = 16, colW = W - mL * 2 - 16;
    int rowY = 14;
    gui_make(g_hMain, L"Static", L"服务器地址", 0, mL, rowY + 3, 70, 16, 0);
    g_hServer = gui_make(g_hMain, L"Edit", L"", WS_BORDER | WS_TABSTOP, mL + 75, rowY, colW - 75, 22, IDC_SERVER);
    rowY += 30;
    gui_make(g_hMain, L"Static", L"账号", 0, mL, rowY + 3, 70, 16, 0);
    g_hUser = gui_make(g_hMain, L"Edit", L"", WS_BORDER | WS_TABSTOP, mL + 75, rowY, 160, 22, IDC_USER);
    gui_make(g_hMain, L"Static", L"密码", 0, mL + 245, rowY + 3, 34, 16, 0);
    g_hPass = gui_make(g_hMain, L"Edit", L"", WS_BORDER | WS_TABSTOP | ES_PASSWORD, mL + 282, rowY, 160, 22, IDC_PASS);
    gui_make(g_hMain, L"Button", L"保存账号", WS_TABSTOP | BS_PUSHBUTTON, mL + 452, rowY - 1, 92, 25, IDC_SAVEACC);
    rowY += 40;
    gui_make(g_hMain, L"Button", L"上传规则（满足任一匹配即上传）", BS_GROUPBOX, mL, rowY, W - mL * 2, 300, 0);
    rowY += 22;
    gui_make(g_hMain, L"Static", L"本地路径(文件/目录/通配符)", 0, mL + 12, rowY + 3, 160, 16, 0);
    g_hLocal = gui_make(g_hMain, L"Edit", L"", WS_BORDER | WS_TABSTOP, mL + 12, rowY + 20, colW - 24, 22, IDC_LOCAL);
    rowY += 50;
    gui_make(g_hMain, L"Static", L"保存到的文件夹", 0, mL + 12, rowY + 3, 95, 16, 0);
    g_hDir = gui_make(g_hMain, L"Edit", L"", WS_BORDER | WS_TABSTOP, mL + 110, rowY, 180, 22, IDC_DIR);
    gui_make(g_hMain, L"Static", L"软件ID", 0, mL + 300, rowY + 3, 46, 16, 0);
    g_hSid = gui_make(g_hMain, L"Edit", L"S0006", WS_BORDER | WS_TABSTOP, mL + 348, rowY, 80, 22, IDC_SID);
    gui_make(g_hMain, L"Button", L"添加", WS_TABSTOP | BS_PUSHBUTTON, mL + 440, rowY - 1, 100, 25, IDC_ADDRULE);
    rowY += 34;
    g_hList = gui_make(g_hMain, L"ListBox", L"", WS_BORDER | LBS_NOTIFY | WS_VSCROLL | WS_TABSTOP,
                       mL + 12, rowY, colW - 24, 150, IDC_LIST);
    rowY += 156;
    gui_make(g_hMain, L"Button", L"删除选中规则", WS_TABSTOP | BS_PUSHBUTTON, mL + 12, rowY, 110, 25, IDC_DELRULE);
    rowY += 34;
    g_hTip = gui_make(g_hMain, L"Static",
                      L"拖入文件可自动填路径。命令行：autoupload.exe 文件路径；无匹配规则时静默退出。",
                      0, mL + 12, rowY, colW - 24, 28, IDC_TIP);

    DragAcceptFiles(g_hMain, TRUE);
    gui_load_account();
    gui_reload_rules();
    ShowWindow(g_hMain, SW_SHOW);
    UpdateWindow(g_hMain);
    MSG msg;
    while (GetMessageW(&msg, NULL, 0, 0) > 0) {
        if (IsDialogMessageW(g_hMain, &msg)) continue;
        TranslateMessage(&msg);
        DispatchMessageW(&msg);
    }
    return 0;
}

/* ============================================================
 * 入口：有参数=命令行上传；无参数=设置界面
 * ============================================================ */
int WINAPI wWinMain(HINSTANCE hInst, HINSTANCE prev, PWSTR cmdLine, int show)
{
    (void)prev; (void)show;
    g_hInst = hInst;
    SetProcessDPIAware();   /* 高分屏按真实像素布局，避免系统拉伸模糊 */
    init_ini_path();
#ifdef AU_DEBUG
    _wsetlocale(LC_ALL, L"");   /* 调试日志可输出中文 */
#endif

    int argc = 0;
    WCHAR **argv = CommandLineToArgvW(GetCommandLineW(), &argc);
    if (argv && argc > 1) {
        int rc = run_cli(argv, argc);
        LocalFree(argv);
        return rc;
    }
    if (argv) LocalFree(argv);
    return run_gui();
}
