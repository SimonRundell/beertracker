/**
 * Reusable avatar that supports circular or square displays with a fallback initial or label.
 * @param {Object} props
 * @param {string} [props.src] Image URL/base64 to render.
 * @param {string} [props.name] Name used for alt text and fallback initial.
 * @param {string} [props.email] Secondary source for fallback initial.
 * @param {'default'|'small'} [props.size] Circle size preset.
 * @param {'circle'|'square'} [props.shape] Shape of the avatar.
 * @param {string} [props.emptyLabel] Text shown for empty square avatars.
 * @param {string} [props.alt] Alt text override.
 * @param {string} [props.className] Additional class names.
 */
export default function Avatar({
  src,
  name,
  email,
  size = 'default',
  shape = 'circle',
  emptyLabel = 'No avatar',
  alt,
  className = '',
}) {
  const labelSource = name || email || '?'
  const fallbackChar = (labelSource && labelSource.charAt(0).toUpperCase()) || '?'
  const baseClass = shape === 'square' ? 'avatar-preview' : 'avatar'

  const classes = [baseClass]
  if (shape === 'circle' && size === 'small') classes.push('avatar--small')
  if (!src && shape === 'circle') classes.push('avatar--fallback')
  if (!src && shape === 'square') classes.push('avatar-preview--empty')
  if (className) classes.push(className)

  const altText = alt || `${name || email || 'User'} avatar`

  if (src) {
    return <img src={src} alt={altText} className={classes.join(' ')} />
  }

  if (shape === 'square') {
    return <div className={classes.join(' ')}>{emptyLabel}</div>
  }

  return <div className={classes.join(' ')}>{fallbackChar}</div>
}
