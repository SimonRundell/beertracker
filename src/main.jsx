import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'
import { loadConfig } from './api/config.js'

const root = createRoot(document.getElementById('root'))

loadConfig()
  .then(() => {
    root.render(<App />)
  })
  .catch((err) => {
    root.render(
      <div className="config-error">
        <h1>Configuration error</h1>
        <p>{err.message}</p>
        <p>Copy <code>public/config.example.json</code> to <code>public/config.json</code> and set your API base URL.</p>
      </div>
    )
  })
