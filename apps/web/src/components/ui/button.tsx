import type { ButtonHTMLAttributes, ReactNode } from "react";

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: string;
    size?: string;
    children?: ReactNode;
};

export function Button({ children, className, type = "button", ...rest }: Props) {
    return (
        <button type={type} className={className} {...rest}>
            {children}
        </button>
    );
}
