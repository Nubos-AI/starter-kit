export interface SubmitTarget<Errors> {
  updateUrl: string | null;
  storeUrl: string;
  onSuccess: () => void;
  onError: (errors: Errors) => void;
}

interface VisitOptions<Errors> {
  preserveScroll: boolean;
  onSuccess: () => void;
  onError: (errors: Errors) => void;
}

interface TransformableForm<Errors> {
  transform: (build: () => Record<string, unknown>) => {
    put: (url: string, options: VisitOptions<Errors>) => void;
    post: (url: string, options: VisitOptions<Errors>) => void;
  };
}

export function submitCreateOrUpdate<Errors>(
  form: TransformableForm<Errors>,
  payload: () => Record<string, unknown>,
  target: SubmitTarget<Errors>,
): void {
  const options = {
    preserveScroll: true,
    onSuccess: target.onSuccess,
    onError: target.onError,
  };
  const request = form.transform(payload);

  if (target.updateUrl !== null) {
    request.put(target.updateUrl, options);

    return;
  }

  request.post(target.storeUrl, options);
}
