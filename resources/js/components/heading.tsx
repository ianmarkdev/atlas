export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-0.5'}>
            <h2
                className={
                    variant === 'small'
                        ? 'mb-1.5 type-card-title text-text-primary'
                        : 'mb-2 type-heading-2 text-text-primary'
                }
            >
                {title}
            </h2>
            {description && (
                <p className="type-body text-text-muted">{description}</p>
            )}
        </header>
    );
}
