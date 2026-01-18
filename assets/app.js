const DB_NAME = "glitchlite-db";
const STORE_NAME = "projects";
const DEFAULT_PROJECT_ID = "default";
const TEXT_EXTS = new Set([
  "html",
  "htm",
  "css",
  "js",
  "json",
  "txt",
  "md",
  "svg",
  "csv",
]);
const MIME_BY_EXT = {
  html: "text/html",
  htm: "text/html",
  css: "text/css",
  js: "text/javascript",
  json: "application/json",
  txt: "text/plain",
  md: "text/markdown",
  svg: "image/svg+xml",
  png: "image/png",
  jpg: "image/jpeg",
  jpeg: "image/jpeg",
  gif: "image/gif",
  webp: "image/webp",
  ico: "image/x-icon",
  mp3: "audio/mpeg",
  wav: "audio/wav",
  mp4: "video/mp4",
  webm: "video/webm",
  ogg: "audio/ogg",
};

const state = {
  projectId: DEFAULT_PROJECT_ID,
  files: new Map(),
  currentPath: null,
  previewUrls: [],
  saveTimer: null,
  previewTimer: null,
};

const elements = {
  fileTree: document.getElementById("fileTree"),
  editor: document.getElementById("editor"),
  currentFileLabel: document.getElementById("currentFileLabel"),
  binaryNotice: document.getElementById("binaryNotice"),
  saveStatus: document.getElementById("saveStatus"),
  previewFrame: document.getElementById("previewFrame"),
  previewWarnings: document.getElementById("previewWarnings"),
  newProjectBtn: document.getElementById("newProjectBtn"),
  importZipBtn: document.getElementById("importZipBtn"),
  exportZipBtn: document.getElementById("exportZipBtn"),
  publishBtn: document.getElementById("publishBtn"),
  addFileBtn: document.getElementById("addFileBtn"),
  uploadFileBtn: document.getElementById("uploadFileBtn"),
  refreshPreviewBtn: document.getElementById("refreshPreviewBtn"),
  zipInput: document.getElementById("zipInput"),
  fileInput: document.getElementById("fileInput"),
};

function normalizePath(path) {
  const normalized = path.replace(/\\/g, "/");
  const parts = [];
  for (const part of normalized.split("/")) {
    if (!part || part === ".") continue;
    if (part === "..") {
      parts.pop();
      continue;
    }
    parts.push(part);
  }
  return parts.join("/");
}

function dirname(path) {
  const idx = path.lastIndexOf("/");
  return idx === -1 ? "" : path.slice(0, idx);
}

function resolvePath(baseDir, relPath) {
  if (relPath.startsWith("/")) return relPath;
  if (!baseDir) return normalizePath(relPath);
  return normalizePath(`${baseDir}/${relPath}`);
}

function extname(path) {
  const parts = path.split(".");
  return parts.length > 1 ? parts.pop().toLowerCase() : "";
}

function isTextFile(path) {
  return TEXT_EXTS.has(extname(path));
}

function fileMime(path) {
  return MIME_BY_EXT[extname(path)] || "application/octet-stream";
}

function createDefaultFiles() {
  return [
    {
      path: "index.html",
      kind: "text",
      data: `<!doctype html>\n<html lang=\"en\">\n  <head>\n    <meta charset=\"utf-8\" />\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />\n    <title>My Project</title>\n    <link rel=\"stylesheet\" href=\"style.css\" />\n  </head>\n  <body>\n    <main class=\"stage\">\n      <h1>Make something</h1>\n      <p>Start editing the files on the left.</p>\n      <button id=\"btn\">Click me</button>\n    </main>\n    <script src=\"script.js\"></script>\n  </body>\n</html>\n`,
      mime: "text/html",
    },
    {
      path: "style.css",
      kind: "text",
      data: `:root {\n  color-scheme: light;\n  font-family: \"Trebuchet MS\", sans-serif;\n}\n\nbody {\n  margin: 0;\n  min-height: 100vh;\n  display: grid;\n  place-items: center;\n  background: linear-gradient(135deg, #f7f2ea, #fde6c8);\n}\n\n.stage {\n  text-align: center;\n  padding: 48px;\n  background: white;\n  border-radius: 24px;\n  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.12);\n}\n\nbutton {\n  margin-top: 20px;\n  border: none;\n  background: #d65a31;\n  color: white;\n  padding: 12px 18px;\n  border-radius: 999px;\n  font-weight: 600;\n}\n`,
      mime: "text/css",
    },
    {
      path: "script.js",
      kind: "text",
      data: `const btn = document.getElementById("btn");\n\nbtn?.addEventListener("click", () => {\n  btn.textContent = "Nice!";\n});\n`,
      mime: "text/javascript",
    },
  ];
}

async function openDB() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME);
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function dbGet(key) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, "readonly");
    const store = tx.objectStore(STORE_NAME);
    const request = store.get(key);
    request.onsuccess = () => resolve(request.result || null);
    request.onerror = () => reject(request.error);
  });
}

async function dbSet(key, value) {
  const db = await openDB();
  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, "readwrite");
    const store = tx.objectStore(STORE_NAME);
    const request = store.put(value, key);
    request.onsuccess = () => resolve();
    request.onerror = () => reject(request.error);
  });
}

function setStatus(text, hold = 1200) {
  elements.saveStatus.textContent = text;
  if (hold) {
    clearTimeout(state.statusTimer);
    state.statusTimer = setTimeout(() => {
      elements.saveStatus.textContent = "Idle";
    }, hold);
  }
}

function getFile(path) {
  return state.files.get(path) || null;
}

function setFile(file) {
  state.files.set(file.path, file);
}

function removeFile(path) {
  state.files.delete(path);
  if (state.currentPath === path) {
    state.currentPath = null;
    elements.editor.value = "";
    elements.currentFileLabel.textContent = "Select a file";
  }
}

function renderFileTree() {
  elements.fileTree.innerHTML = "";
  const paths = Array.from(state.files.keys()).sort((a, b) => a.localeCompare(b));
  for (const path of paths) {
    const item = document.createElement("div");
    item.className = "file-item";
    if (path === state.currentPath) item.classList.add("active");

    const label = document.createElement("span");
    label.textContent = path;

    const removeBtn = document.createElement("button");
    removeBtn.className = "icon-btn";
    removeBtn.textContent = "×";
    removeBtn.title = "Delete file";
    removeBtn.addEventListener("click", (event) => {
      event.stopPropagation();
      if (confirm(`Delete ${path}?`)) {
        removeFile(path);
        renderFileTree();
        queueSave();
        queuePreview();
      }
    });

    item.append(label, removeBtn);
    item.addEventListener("click", () => openFile(path));
    elements.fileTree.appendChild(item);
  }
}

function openFile(path) {
  const file = getFile(path);
  if (!file) return;
  state.currentPath = path;
  elements.currentFileLabel.textContent = path;
  renderFileTree();

  if (file.kind === "binary") {
    elements.binaryNotice.classList.remove("hidden");
    elements.editor.value = "";
    elements.editor.disabled = true;
  } else {
    elements.binaryNotice.classList.add("hidden");
    elements.editor.disabled = false;
    elements.editor.value = file.data;
  }
}

function updateCurrentFile(value) {
  const path = state.currentPath;
  if (!path) return;
  const file = getFile(path);
  if (!file || file.kind === "binary") return;
  file.data = value;
  setFile(file);
}

function serializeProject() {
  const files = Array.from(state.files.values()).map((file) => {
    const payload = {
      path: file.path,
      kind: file.kind,
      mime: file.mime,
    };
    if (file.kind === "binary") {
      payload.data = Array.from(new Uint8Array(file.data));
    } else {
      payload.data = file.data;
    }
    return payload;
  });
  return { updatedAt: Date.now(), files };
}

function loadProject(project) {
  state.files = new Map();
  for (const file of project.files) {
    let data = file.data;
    if (file.kind === "binary") {
      data = new Uint8Array(file.data).buffer;
    }
    setFile({ path: file.path, kind: file.kind, mime: file.mime, data });
  }
}

function queueSave() {
  clearTimeout(state.saveTimer);
  setStatus("Saving...", 0);
  state.saveTimer = setTimeout(async () => {
    try {
      await dbSet(state.projectId, serializeProject());
      setStatus("Saved");
    } catch (error) {
      console.error(error);
      setStatus("Save failed", 2000);
    }
  }, 400);
}

function queuePreview() {
  clearTimeout(state.previewTimer);
  state.previewTimer = setTimeout(renderPreview, 200);
}

function clearPreviewUrls() {
  for (const url of state.previewUrls) {
    URL.revokeObjectURL(url);
  }
  state.previewUrls = [];
}

function addPreviewUrl(url) {
  state.previewUrls.push(url);
  return url;
}

function createBlobUrl(file, overrideMime) {
  const mime = overrideMime || file.mime || fileMime(file.path);
  const blob = file.kind === "binary" ? new Blob([file.data], { type: mime }) : new Blob([file.data], { type: mime });
  return addPreviewUrl(URL.createObjectURL(blob));
}

function isRelativeUrl(url) {
  return !/^(https?:|data:|blob:|#|mailto:|tel:|\\/\\/)/i.test(url);
}

function isAbsolutePath(url) {
  return url.startsWith("/") && !url.startsWith("//");
}

function replaceCssUrls(cssText, baseDir, warnings) {
  return cssText.replace(/url\(([^)]+)\)/g, (match, raw) => {
    let url = raw.trim().replace(/^['"]|['"]$/g, "");
    if (!url || !isRelativeUrl(url)) return match;
    if (isAbsolutePath(url)) {
      warnings.add(`Absolute path in CSS: ${url}`);
      return match;
    }
    const resolved = resolvePath(baseDir, url);
    const file = getFile(resolved);
    if (!file) return match;
    const blobUrl = createBlobUrl(file);
    return `url(${blobUrl})`;
  });
}

function rewriteDocument(doc, htmlPath, warnings) {
  const baseDir = dirname(htmlPath);

  const linkNodes = Array.from(doc.querySelectorAll("link[href]"));
  for (const link of linkNodes) {
    const href = link.getAttribute("href");
    if (!href || !isRelativeUrl(href)) continue;
    if (isAbsolutePath(href)) {
      warnings.add(`Absolute path in HTML: ${href}`);
      continue;
    }
    const resolved = resolvePath(baseDir, href);
    const file = getFile(resolved);
    if (!file) continue;
    if (link.rel === "stylesheet") {
      const cssText = file.kind === "binary" ? "" : String(file.data || "");
      const updatedCss = replaceCssUrls(cssText, dirname(resolved), warnings);
      const blobUrl = createBlobUrl({ ...file, data: updatedCss, kind: "text" }, "text/css");
      link.setAttribute("href", blobUrl);
    } else {
      link.setAttribute("href", createBlobUrl(file));
    }
  }

  const scriptNodes = Array.from(doc.querySelectorAll("script[src]"));
  for (const script of scriptNodes) {
    const src = script.getAttribute("src");
    if (!src || !isRelativeUrl(src)) continue;
    if (isAbsolutePath(src)) {
      warnings.add(`Absolute path in HTML: ${src}`);
      continue;
    }
    const resolved = resolvePath(baseDir, src);
    const file = getFile(resolved);
    if (!file) continue;
    script.setAttribute("src", createBlobUrl(file));
  }

  const srcNodes = Array.from(doc.querySelectorAll("[src]"));
  for (const node of srcNodes) {
    if (node.tagName === "SCRIPT") continue;
    const src = node.getAttribute("src");
    if (!src || !isRelativeUrl(src)) continue;
    if (isAbsolutePath(src)) {
      warnings.add(`Absolute path in HTML: ${src}`);
      continue;
    }
    const resolved = resolvePath(baseDir, src);
    const file = getFile(resolved);
    if (!file) continue;
    node.setAttribute("src", createBlobUrl(file));
  }

  const styleNodes = Array.from(doc.querySelectorAll("style"));
  for (const style of styleNodes) {
    const cssText = style.textContent || "";
    const updatedCss = replaceCssUrls(cssText, baseDir, warnings);
    style.textContent = updatedCss;
  }
}

function renderPreview() {
  clearPreviewUrls();
  const warnings = new Set();
  const htmlFile = getFile("index.html") || Array.from(state.files.values()).find((file) => file.path.endsWith(".html"));
  let html = "<!doctype html><html><body><p>No HTML file found.</p></body></html>";
  let htmlPath = "index.html";

  if (htmlFile && htmlFile.kind !== "binary") {
    html = htmlFile.data;
    htmlPath = htmlFile.path;
  }

  const parser = new DOMParser();
  const doc = parser.parseFromString(html, "text/html");
  rewriteDocument(doc, htmlPath, warnings);

  const output = "<!doctype html>\n" + doc.documentElement.outerHTML;
  elements.previewFrame.srcdoc = output;

  if (warnings.size) {
    elements.previewWarnings.textContent = `Warnings: ${Array.from(warnings).join(" | ")}`;
  } else {
    elements.previewWarnings.textContent = "";
  }
}

function openFirstFile() {
  if (getFile("index.html")) {
    openFile("index.html");
    return;
  }
  const first = Array.from(state.files.keys()).sort()[0];
  if (first) openFile(first);
}

async function loadInitialProject() {
  const stored = await dbGet(state.projectId);
  if (stored && stored.files?.length) {
    loadProject(stored);
  } else {
    const defaults = createDefaultFiles();
    defaults.forEach((file) => setFile({ ...file }));
    await dbSet(state.projectId, serializeProject());
  }

  renderFileTree();
  openFirstFile();
  renderPreview();
}

function addFile(path, data = "", kind = null) {
  const normalized = normalizePath(path);
  if (!normalized) return;
  const fileKind = kind || (isTextFile(normalized) ? "text" : "binary");
  const payload = fileKind === "binary" ? (data instanceof ArrayBuffer ? data : new ArrayBuffer(0)) : String(data);
  const file = {
    path: normalized,
    kind: fileKind,
    data: payload,
    mime: fileMime(normalized),
  };
  setFile(file);
  renderFileTree();
  queueSave();
  queuePreview();
}

async function handleFileUpload(files) {
  for (const file of files) {
    const path = normalizePath(file.name);
    const kind = isTextFile(path) ? "text" : "binary";
    if (kind === "text") {
      const text = await file.text();
      addFile(path, text, "text");
    } else {
      const buffer = await file.arrayBuffer();
      addFile(path, buffer, "binary");
    }
  }
}

async function ensureJSZip() {
  if (window.JSZip) return window.JSZip;
  return new Promise((resolve, reject) => {
    const script = document.createElement("script");
    script.src = "https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js";
    script.onload = () => resolve(window.JSZip);
    script.onerror = () => reject(new Error("Failed to load JSZip"));
    document.head.appendChild(script);
  });
}

async function importZip(file) {
  try {
    const JSZip = await ensureJSZip();
    const zip = await JSZip.loadAsync(file);
    state.files = new Map();
    const entries = Object.keys(zip.files);
    for (const path of entries) {
      const entry = zip.files[path];
      if (entry.dir) continue;
      const normalized = normalizePath(path);
      const kind = isTextFile(normalized) ? "text" : "binary";
      if (kind === "text") {
        const content = await entry.async("string");
        setFile({ path: normalized, kind: "text", data: content, mime: fileMime(normalized) });
      } else {
        const buffer = await entry.async("arraybuffer");
        setFile({ path: normalized, kind: "binary", data: buffer, mime: fileMime(normalized) });
      }
    }
    renderFileTree();
    openFirstFile();
    queueSave();
    queuePreview();
  } catch (error) {
    console.error(error);
    alert("Import failed. Check the console for details.");
  }
}

async function exportZip() {
  try {
    const JSZip = await ensureJSZip();
    const zip = new JSZip();
    for (const file of state.files.values()) {
      if (file.kind === "binary") {
        zip.file(file.path, file.data);
      } else {
        zip.file(file.path, file.data);
      }
    }
    const blob = await zip.generateAsync({ type: "blob" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = `project-${Date.now()}.zip`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  } catch (error) {
    console.error(error);
    alert("Export failed. Check the console for details.");
  }
}

function resetProject() {
  if (!confirm("Start a new project? This will clear the current workspace.")) return;
  state.files = new Map();
  const defaults = createDefaultFiles();
  defaults.forEach((file) => setFile({ ...file }));
  renderFileTree();
  openFirstFile();
  queueSave();
  queuePreview();
}

function setupEvents() {
  elements.editor.addEventListener("input", () => {
    updateCurrentFile(elements.editor.value);
    queueSave();
    queuePreview();
  });

  elements.newProjectBtn.addEventListener("click", resetProject);
  elements.importZipBtn.addEventListener("click", () => elements.zipInput.click());
  elements.exportZipBtn.addEventListener("click", exportZip);
  elements.publishBtn.addEventListener("click", () => {
    alert("Publishing is wired up later to the PHP endpoint.");
  });
  elements.addFileBtn.addEventListener("click", () => {
    const path = prompt("New file path (e.g. assets/main.css):");
    if (path) addFile(path);
  });
  elements.uploadFileBtn.addEventListener("click", () => elements.fileInput.click());
  elements.refreshPreviewBtn.addEventListener("click", renderPreview);

  elements.zipInput.addEventListener("change", (event) => {
    const [file] = event.target.files || [];
    if (file) importZip(file);
    event.target.value = "";
  });

  elements.fileInput.addEventListener("change", (event) => {
    const files = Array.from(event.target.files || []);
    if (files.length) handleFileUpload(files);
    event.target.value = "";
  });
}

setupEvents();
loadInitialProject();
