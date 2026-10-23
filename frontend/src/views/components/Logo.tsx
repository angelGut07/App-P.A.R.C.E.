import { motion } from 'framer-motion';

interface LogoProps {
  size?: 'sm' | 'md' | 'lg';
  showText?: boolean;
}

export default function Logo({ size = 'md', showText = true }: LogoProps) {
  const sizes = {
    sm: 'w-8 h-8',
    md: 'w-12 h-12',
    lg: 'w-16 h-16',
  };

  const textSizes = {
    sm: 'text-xl',
    md: 'text-2xl',
    lg: 'text-4xl',
  };

  return (
    <motion.div
      className="flex items-center gap-3"
      initial={{ opacity: 0, scale: 0.8 }}
      animate={{ opacity: 1, scale: 1 }}
      transition={{ duration: 0.5 }}
    >
      <img
        src="/Logo.jpg"
        alt="P.A.R.C.E Logo"
        className={`${sizes[size]} object-contain mix-blend-lighten`}
        style={{ filter: 'brightness(1.2) contrast(1.1)' }}
      />
      {showText && (
        <span className={`${textSizes[size]} font-bold text-gradient`}>
          P.A.R.C.E
        </span>
      )}
    </motion.div>
  );
}
