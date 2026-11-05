const STORES = ['meta', 'teams', 'tasks', 'comments', 'announcements', 'notifications', 'queue'];

function requestToPromise(request) {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

function transactionDone(tx) {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error || new Error('The local save was aborted.'));
  });
}

class IdbDriver {
  async init() {
    this.db = await new Promise((resolve, reject) => {
      const open = indexedDB.open('orbit', 1);
      open.onupgradeneeded = () => {
        const db = open.result;
        for (const name of STORES) {
          if (!db.objectStoreNames.contains(name)) {
            const key = name === 'meta' ? 'key' : name === 'queue' ? 'op_id' : 'id';
            db.createObjectStore(name, { keyPath: key });
          }
        }
      };
      open.onsuccess = () => resolve(open.result);
      open.onerror = () => reject(open.error);
    });
  }

  key(store, row) {
    if (store === 'meta') return row.key;
    if (store === 'queue') return row.op_id;
    return row.id;
  }

  async put(store, row) {
    const tx = this.db.transaction(store, 'readwrite');
    tx.objectStore(store).put(row);
    await transactionDone(tx);
  }

  async get(store, id) {
    const tx = this.db.transaction(store, 'readonly');
    return requestToPromise(tx.objectStore(store).get(id));
  }

  async all(store) {
    const tx = this.db.transaction(store, 'readonly');
    return requestToPromise(tx.objectStore(store).getAll());
  }

  async delete(store, id) {
    const tx = this.db.transaction(store, 'readwrite');
    tx.objectStore(store).delete(id);
    await transactionDone(tx);
  }
}

class SqliteDriver {
  constructor(plugin) {
    this.plugin = plugin;
    this.database = 'orbit';
  }

  async init() {
    try {
      await this.plugin.createConnection({
        database: this.database,
        version: 1,
        encrypted: false,
        mode: 'no-encryption',
      });
    } catch {
      // The connection already exists after a previous launch.
    }
    await this.plugin.open({ database: this.database });
    await this.plugin.execute({
      database: this.database,
      statements: `CREATE TABLE IF NOT EXISTS records (
        store TEXT NOT NULL,
        id TEXT NOT NULL,
        json TEXT NOT NULL,
        PRIMARY KEY (store, id)
      );`,
    });
  }

  async put(store, row) {
    const id = String(store === 'meta' ? row.key : store === 'queue' ? row.op_id : row.id);
    await this.plugin.run({
      database: this.database,
      statement: 'DELETE FROM records WHERE store = ? AND id = ?',
      values: [store, id],
    });
    await this.plugin.run({
      database: this.database,
      statement: 'INSERT INTO records (store, id, json) VALUES (?, ?, ?)',
      values: [store, id, JSON.stringify(row)],
    });
  }

  async get(store, id) {
    const result = await this.plugin.query({
      database: this.database,
      statement: 'SELECT json FROM records WHERE store = ? AND id = ?',
      values: [store, String(id)],
    });
    const value = result.values?.[0]?.json;
    return value ? JSON.parse(value) : undefined;
  }

  async all(store) {
    const result = await this.plugin.query({
      database: this.database,
      statement: 'SELECT json FROM records WHERE store = ?',
      values: [store],
    });
    return (result.values || []).map((row) => JSON.parse(row.json));
  }

  async delete(store, id) {
    await this.plugin.run({
      database: this.database,
      statement: 'DELETE FROM records WHERE store = ? AND id = ?',
      values: [store, String(id)],
    });
  }
}

let driver;

export const db = {
  async init() {
    const plugin = window.Capacitor?.isNativePlatform?.() ? window.Capacitor?.Plugins?.CapacitorSQLite : null;
    if (plugin) {
      driver = new SqliteDriver(plugin);
      await driver.init();
      return 'sqlite';
    }
    driver = new IdbDriver();
    await driver.init();
    return 'indexeddb';
  },
  put: (store, row) => driver.put(store, row),
  get: (store, id) => driver.get(store, id),
  all: (store) => driver.all(store),
  delete: (store, id) => driver.delete(store, id),
  async setMeta(key, value) {
    await driver.put('meta', { key, value });
  },
  async meta(key) {
    const row = await driver.get('meta', key);
    return row ? row.value : null;
  },
};
